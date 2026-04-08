<?php

declare(strict_types=1);

namespace Test\Unit\Mongo;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Myxa\Application;
use Myxa\Database\Attributes\Cast;
use Myxa\Database\Attributes\Guarded;
use Myxa\Database\Attributes\Hidden;
use Myxa\Database\Attributes\Hook;
use Myxa\Database\Attributes\Internal;
use Myxa\Database\Model\CastType;
use Myxa\Database\Model\HookEvent;
use Myxa\Mongo\Connection\InMemoryMongoCollection;
use Myxa\Mongo\Connection\MongoConnection;
use Myxa\Mongo\MongoManager;
use Myxa\Mongo\MongoModel;
use Myxa\Mongo\MongoServiceProvider;
use Myxa\Support\Facades\Mongo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UserDocument extends MongoModel
{
    protected string $collection = 'users';

    protected string|int|null $_id = null;

    protected string $email = '';

    protected string $status = '';
}

final class SecureUserDocument extends MongoModel
{
    protected string $collection = 'users';

    protected string|int|null $_id = null;

    protected string $email = '';

    #[Guarded]
    #[Hidden]
    protected ?string $secret = null;
}

final class CastedUserDocument extends MongoModel
{
    protected string $collection = 'users';

    protected string|int|null $_id = null;

    #[Cast(CastType::DateTimeImmutable, format: DATE_ATOM)]
    protected ?DateTimeImmutable $created_at = null;
}

final class ConnectedUserDocument extends MongoModel
{
    protected string $collection = 'users';

    protected ?string $connection = 'mongo-main';

    protected string|int|null $_id = null;

    protected string $email = '';
}

final class InvalidCollectionDocument extends MongoModel
{
    protected string $collection = ' ';

    protected string|int|null $_id = null;
}

final class InvalidPrimaryKeyDocument extends MongoModel
{
    protected string $collection = 'users';

    protected string $primaryKey = ' ';

    protected string|int|null $_id = null;
}

final class InvalidConnectionDocument extends MongoModel
{
    protected string $collection = 'users';

    protected ?string $connection = ' ';

    protected string|int|null $_id = null;
}

final class ObservedUserDocument extends MongoModel
{
    protected string $collection = 'users';

    protected string|int|null $_id = null;

    protected string $email = '';

    protected string $status = '';

    #[Internal]
    public array $hookLog = [];

    #[Internal]
    public array $hookOriginal = [];

    #[Internal]
    public array $hookChanges = [];

    #[Hook(HookEvent::BeforeUpdate)]
    protected function beforeUpdate(): void
    {
        $this->hookLog[] = 'before_update';
        $this->hookOriginal = $this->getOriginal();
    }

    #[Hook(HookEvent::AfterUpdate)]
    protected function afterUpdate(): void
    {
        $this->hookLog[] = 'after_update';
        $this->hookChanges = $this->getChanges();
    }

    #[Hook(HookEvent::AfterDelete)]
    protected function afterDelete(): void
    {
        $this->hookLog[] = 'after_delete';
        $this->hookOriginal = $this->getOriginal();
        $this->hookChanges = $this->getChanges();
    }
}

#[CoversClass(Mongo::class)]
#[CoversClass(MongoConnection::class)]
#[CoversClass(InMemoryMongoCollection::class)]
#[CoversClass(MongoManager::class)]
#[CoversClass(MongoModel::class)]
#[CoversClass(MongoServiceProvider::class)]
final class MongoTest extends TestCase
{
    private const string CONNECTION_ALIAS = 'mongo-main';

    protected function tearDown(): void
    {
        Mongo::clearManager();
        MongoModel::clearManager();
        MongoConnection::unregister(self::CONNECTION_ALIAS);
        MongoConnection::unregister('fallback');
        MongoConnection::unregister('duplicate');
    }

    public function testManagerResolvesManagedConnectionsAndCollections(): void
    {
        $manager = new MongoManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeConnection());

        $document = $manager->collection('users')->findOne(['_id' => 1]);

        self::assertSame('john@example.com', $document['email']);
    }

    public function testManagerResolvesFactoryConnections(): void
    {
        $manager = new MongoManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, fn (): MongoConnection => $this->makeConnection());

        $document = $manager->collection('users')->findOne(['status' => 'active']);

        self::assertSame('john@example.com', $document['email']);
    }

    public function testManagerRejectsInvalidFactorySignature(): void
    {
        $manager = new MongoManager(self::CONNECTION_ALIAS);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Connection factory for alias "mongo-main" must accept zero or one parameter.');

        $manager->addConnection(
            self::CONNECTION_ALIAS,
            static fn (MongoManager $db, string $alias): MongoConnection => throw new RuntimeException('not reached'),
        );
    }

    public function testManagerUsesRegisteredFallbackConnection(): void
    {
        $fallback = $this->makeConnection();
        MongoConnection::register('fallback', $fallback, true);

        $manager = new MongoManager('fallback');

        self::assertTrue($manager->hasConnection('fallback'));
        self::assertSame($fallback, $manager->connection());
    }

    public function testManagerSupportsDefaultConnectionChangesAndMissingConnections(): void
    {
        $manager = new MongoManager(' main ');
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeConnection());
        $manager->setDefaultConnection(self::CONNECTION_ALIAS);

        self::assertSame(self::CONNECTION_ALIAS, $manager->getDefaultConnection());
        self::assertSame('john@example.com', $manager->collection('users')->findOne(['_id' => 1])['email']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection alias "missing" is not registered.');

        $manager->connection('missing');
    }

    public function testManagerRejectsDuplicateAliasAndInvalidFactoryResults(): void
    {
        $manager = new MongoManager(self::CONNECTION_ALIAS);
        $manager->addConnection('duplicate', $this->makeConnection());

        try {
            $manager->addConnection('duplicate', $this->makeConnection());
            self::fail('Expected duplicate alias exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Connection alias "duplicate" is already registered.', $exception->getMessage());
        }

        $manager->addConnection('broken', static fn () => new \stdClass(), true);

        $this->expectException(\TypeError::class);

        $manager->connection('broken');
    }

    public function testServiceProviderBootstrapsManagerAndFacade(): void
    {
        $app = new Application();
        $app->register(new MongoServiceProvider(
            connections: [self::CONNECTION_ALIAS => $this->makeConnection()],
            defaultConnection: self::CONNECTION_ALIAS,
        ));

        $app->boot();

        $manager = $app->make(MongoManager::class);
        $document = Mongo::collection('users')->findOne(['_id' => 2]);

        self::assertSame($manager, $app->make('mongo'));
        self::assertSame($manager, Mongo::getManager());
        self::assertSame('jane@example.com', $document['email']);
    }

    public function testMongoFacadeSupportsDirectConnectionsAndClear(): void
    {
        $manager = new MongoManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeConnection());
        Mongo::setManager($manager);

        self::assertSame('john@example.com', Mongo::connection(self::CONNECTION_ALIAS)->collection('users')->findOne(['_id' => 1])['email']);
        self::assertSame('jane@example.com', Mongo::collection('users', self::CONNECTION_ALIAS)->findOne(['_id' => 2])['email']);

        Mongo::clearManager();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection alias "main" is not registered.');

        Mongo::connection();
    }

    public function testMongoModelSupportsPersistenceAndDirtyTracking(): void
    {
        $manager = new MongoManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeConnection());
        UserDocument::setManager($manager);

        $user = UserDocument::find(1);

        self::assertInstanceOf(UserDocument::class, $user);
        self::assertSame('john@example.com', $user->email);
        self::assertFalse($user->isDirty());

        $user->status = 'archived';

        self::assertTrue($user->isDirty('status'));
        self::assertSame(['status' => 'archived'], $user->getDirty());
        self::assertTrue($user->save());
        self::assertSame(['status' => 'archived'], $user->getChanges());
        self::assertSame('archived', $user->getOriginal('status'));

        $created = UserDocument::create([
            'email' => 'new@example.com',
            'status' => 'draft',
        ]);

        self::assertNotNull($created->getKey());
        self::assertSame('draft', $created->status);
        self::assertTrue($created->delete());
        self::assertNull(UserDocument::find($created->getKey() ?? -1));
    }

    public function testMongoModelSupportsReadOnlyAndCloneBehavior(): void
    {
        $manager = new MongoManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeConnection());
        UserDocument::setManager($manager);

        $newUser = (new UserDocument(['email' => 'readonly@example.com', 'status' => 'draft']))->setReadOnly();
        self::assertTrue($newUser->isReadOnly());
        self::assertFalse($newUser->save());

        $persisted = UserDocument::find(1);
        self::assertInstanceOf(UserDocument::class, $persisted);
        $persisted->setReadOnly();
        self::assertFalse($persisted->delete());

        $cloned = clone UserDocument::find(2);
        self::assertFalse($cloned->exists());
        self::assertNull($cloned->getKey());
    }

    public function testMongoModelSupportsHiddenGuardedAndJsonSerialization(): void
    {
        $manager = new MongoManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeConnection());
        SecureUserDocument::setManager($manager);

        $user = new SecureUserDocument([
            'email' => 'safe@example.com',
            'secret' => 'incoming',
        ]);

        self::assertNull($user->secret);
        $user->secret = 'stored';

        self::assertSame(['_id' => null, 'email' => 'safe@example.com'], $user->toArray());
        self::assertSame('{"_id":null,"email":"safe@example.com"}', $user->toJson());
        self::assertSame(['_id' => null, 'email' => 'safe@example.com'], $user->jsonSerialize());
    }

    public function testMongoModelSupportsCastingAndUnset(): void
    {
        $manager = new MongoManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeConnection());
        CastedUserDocument::setManager($manager);

        $user = new CastedUserDocument([
            'created_at' => '2026-04-08T12:00:00+00:00',
        ]);

        self::assertInstanceOf(DateTimeImmutable::class, $user->created_at);
        self::assertSame(['_id' => null, 'created_at' => '2026-04-08T12:00:00+00:00'], $user->toArray());

        unset($user->created_at);
        self::assertNull($user->created_at);
    }

    public function testMongoModelSupportsMakeHydrateAndConnectionMetadata(): void
    {
        $manager = new MongoManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeConnection());
        ConnectedUserDocument::setManager($manager);

        $made = ConnectedUserDocument::make(['email' => 'meta@example.com']);
        self::assertSame('users', ConnectedUserDocument::collection());
        self::assertSame('_id', ConnectedUserDocument::primaryKey());
        self::assertSame('mongo-main', ConnectedUserDocument::connectionName());
        self::assertSame('meta@example.com', $made->email);

        $hydrated = ConnectedUserDocument::hydrate(['_id' => 'doc-1', 'email' => 'hydrated@example.com'], $manager);
        self::assertTrue($hydrated->exists());
        self::assertSame('hydrated@example.com', $hydrated->getOriginal('email'));
        self::assertFalse($hydrated->wasChanged());
    }

    public function testMongoModelRejectsInvalidMetadataAndUnknownAttributes(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf(
            'Mongo model %s must define a non-empty $collection property.',
            InvalidCollectionDocument::class,
        ));

        InvalidCollectionDocument::collection();
    }

    public function testMongoModelRejectsInvalidPrimaryKeyMetadata(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf(
            'Mongo model %s must define a non-empty $primaryKey property.',
            InvalidPrimaryKeyDocument::class,
        ));

        InvalidPrimaryKeyDocument::primaryKey();
    }

    public function testMongoModelRejectsInvalidConnectionMetadataAndUnknownWrites(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf(
            'Model %s defines an empty metadata property.',
            InvalidConnectionDocument::class,
        ));

        InvalidConnectionDocument::connectionName();
    }

    public function testMongoModelRejectsUnknownMassAssignmentAndUnknownAttributes(): void
    {
        $user = new UserDocument(['email' => 'valid@example.com', 'status' => 'active']);

        try {
            $user->fill(['nickname' => 'Bird']);
            self::fail('Expected mass-assignment exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Cannot mass-assign unknown attribute "nickname"', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot set unknown attribute "nickname"');

        $user->setAttribute('nickname', 'Bird');
    }

    public function testMongoModelSaveAndDeleteHandleMissingPersistedDocumentGracefully(): void
    {
        $manager = new MongoManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeConnection());
        UserDocument::setManager($manager);

        $user = UserDocument::hydrate(['_id' => 999, 'email' => 'missing@example.com', 'status' => 'ghost'], $manager);
        $user->status = 'changed';

        self::assertFalse($user->save());
        self::assertSame([], $user->getChanges());
        self::assertFalse($user->delete());
    }

    public function testMongoHooksExposeOriginalAndChanges(): void
    {
        $manager = new MongoManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeConnection());
        ObservedUserDocument::setManager($manager);

        $user = ObservedUserDocument::find(1);
        self::assertInstanceOf(ObservedUserDocument::class, $user);

        $user->status = 'published';
        self::assertTrue($user->save());
        self::assertSame(['before_update', 'after_update'], $user->hookLog);
        self::assertSame('active', $user->hookOriginal['status']);
        self::assertSame(['status' => 'published'], $user->hookChanges);

        $user->hookLog = [];
        self::assertTrue($user->delete());
        self::assertSame(['after_delete'], $user->hookLog);
        self::assertSame($user->hookOriginal, $user->hookChanges);
        self::assertSame('published', $user->hookOriginal['status']);
    }

    public function testMongoConnectionRegistryAndCollectionHelpers(): void
    {
        $collection = new InMemoryMongoCollection();
        $connection = new MongoConnection();

        self::assertFalse($connection->hasCollection('users'));
        $connection->addCollection('users', $collection);
        self::assertTrue($connection->hasCollection('users'));
        self::assertSame($collection, $connection->collection('users'));

        MongoConnection::register(self::CONNECTION_ALIAS, $connection, true);
        self::assertTrue(MongoConnection::has(self::CONNECTION_ALIAS));
        self::assertSame($connection, MongoConnection::get(self::CONNECTION_ALIAS));

        try {
            MongoConnection::register(self::CONNECTION_ALIAS, $connection);
            self::fail('Expected duplicate connection exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Connection alias "mongo-main" is already registered.', $exception->getMessage());
        }

        MongoConnection::unregister(self::CONNECTION_ALIAS);
        self::assertFalse(MongoConnection::has(self::CONNECTION_ALIAS));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection alias "mongo-main" is not registered.');

        MongoConnection::get(self::CONNECTION_ALIAS);
    }

    public function testMongoConnectionThrowsForMissingCollection(): void
    {
        $connection = new MongoConnection();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Collection "users" is not registered on this Mongo connection.');

        $connection->collection('users');
    }

    public function testInMemoryMongoCollectionSupportsAllBranches(): void
    {
        $collection = new InMemoryMongoCollection();

        self::assertNull($collection->findOne(['_id' => 'missing']));

        $generatedId = $collection->insertOne(['email' => 'auto@example.com']);
        self::assertSame(1, $generatedId);
        self::assertSame('auto@example.com', $collection->findOne(['_id' => 1])['email']);

        self::assertFalse($collection->updateOne(['_id' => 2], ['email' => 'nope']));
        self::assertTrue($collection->updateOne(['_id' => 1], ['email' => 'updated@example.com']));
        self::assertSame(1, $collection->findOne(['_id' => 1])['_id']);
        self::assertSame('updated@example.com', $collection->all()[0]['email']);

        self::assertFalse($collection->deleteOne(['_id' => 2]));
        self::assertTrue($collection->deleteOne(['_id' => 1]));
        self::assertSame([], $collection->all());
    }

    private function makeConnection(): MongoConnection
    {
        $users = new InMemoryMongoCollection();
        $users->insertOne([
            '_id' => 1,
            'email' => 'john@example.com',
            'status' => 'active',
        ]);
        $users->insertOne([
            '_id' => 2,
            'email' => 'jane@example.com',
            'status' => 'pending',
        ]);

        return new MongoConnection([
            'users' => $users,
        ]);
    }
}
