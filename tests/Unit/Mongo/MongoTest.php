<?php

declare(strict_types=1);

namespace Test\Unit\Mongo;

use BadMethodCallException;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use MongoDB\BSON\ObjectId;
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
use Myxa\Mongo\Connection\MongoDbCollection;
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

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Mongo uses _id as the default document key.
    protected string|int|null $_id = null;

    protected string $email = '';

    protected string $status = '';
}

final class SecureUserDocument extends MongoModel
{
    protected string $collection = 'users';

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Mongo uses _id as the default document key.
    protected string|int|null $_id = null;

    protected string $email = '';

    #[Guarded]
    #[Hidden]
    protected ?string $secret = null;
}

final class CastedUserDocument extends MongoModel
{
    protected string $collection = 'users';

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Mongo uses _id as the default document key.
    protected string|int|null $_id = null;

    #[Cast(CastType::DateTimeImmutable, format: DATE_ATOM)]
    protected ?DateTimeImmutable $created_at = null;
}

final class JsonUserDocument extends MongoModel
{
    protected string $collection = 'users';

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Mongo uses _id as the default document key.
    protected string|int|null $_id = null;

    #[Cast(CastType::Json)]
    protected ?array $payload = null;
}

final class ConnectedUserDocument extends MongoModel
{
    protected string $collection = 'users';

    protected ?string $connection = 'mongo-main';

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Mongo uses _id as the default document key.
    protected string|int|null $_id = null;

    protected string $email = '';
}

final class InvalidCollectionDocument extends MongoModel
{
    protected string $collection = ' ';

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Mongo uses _id as the default document key.
    protected string|int|null $_id = null;
}

final class InvalidPrimaryKeyDocument extends MongoModel
{
    protected string $collection = 'users';

    protected string $primaryKey = ' ';

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Mongo uses _id as the default document key.
    protected string|int|null $_id = null;
}

final class InvalidConnectionDocument extends MongoModel
{
    protected string $collection = 'users';

    protected ?string $connection = ' ';

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Mongo uses _id as the default document key.
    protected string|int|null $_id = null;
}

final class ObservedUserDocument extends MongoModel
{
    protected string $collection = 'users';

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Mongo uses _id as the default document key.
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

final class FakeMongoInsertedId
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

final class FakeMongoWriteResult
{
    public function __construct(
        private readonly mixed $insertedId = null,
        private readonly int $matchedCount = 0,
        private readonly int $deletedCount = 0,
    ) {
    }

    public function getInsertedId(): mixed
    {
        return $this->insertedId;
    }

    public function getMatchedCount(): int
    {
        return $this->matchedCount;
    }

    public function getDeletedCount(): int
    {
        return $this->deletedCount;
    }
}

final class FakeMongoCollection
{
    /** @var list<array<string, mixed>> */
    public array $documents = [];

    /** @var array<string, mixed>|null */
    public ?array $lastFilter = null;

    /** @var array<string, mixed>|null */
    public ?array $lastReplacement = null;

    public function findOne(array $filter): ?array
    {
        $this->lastFilter = $filter;

        foreach ($this->documents as $document) {
            if ((string) ($document['_id'] ?? '') === (string) ($filter['_id'] ?? '')) {
                return $document;
            }
        }

        return null;
    }

    public function insertOne(array $document): FakeMongoWriteResult
    {
        $document['_id'] ??= new FakeMongoInsertedId('fake-mongo-id');
        $this->documents[] = $document;

        return new FakeMongoWriteResult(insertedId: $document['_id']);
    }

    public function replaceOne(array $filter, array $replacement): FakeMongoWriteResult
    {
        $this->lastFilter = $filter;
        $this->lastReplacement = $replacement;

        foreach ($this->documents as $index => $document) {
            if ((string) ($document['_id'] ?? '') !== (string) ($filter['_id'] ?? '')) {
                continue;
            }

            $this->documents[$index] = $replacement;

            return new FakeMongoWriteResult(matchedCount: 1);
        }

        return new FakeMongoWriteResult();
    }

    public function deleteOne(array $filter): FakeMongoWriteResult
    {
        foreach ($this->documents as $index => $document) {
            if ((string) ($document['_id'] ?? '') !== (string) ($filter['_id'] ?? '')) {
                continue;
            }

            unset($this->documents[$index]);

            return new FakeMongoWriteResult(deletedCount: 1);
        }

        return new FakeMongoWriteResult();
    }
}

final class FakeMongoArrayDocument
{
    /**
     * @return array<string, mixed>
     */
    public function getArrayCopy(): array
    {
        return [
            '_id' => new FakeMongoInsertedId('array-document-id'),
            'profile' => [
                '_id' => new FakeMongoInsertedId('nested-array-id'),
            ],
            'settings' => new FakeMongoNestedArrayDocument(),
        ];
    }
}

final class FakeMongoNestedArrayDocument
{
    /**
     * @return array<string, mixed>
     */
    public function getArrayCopy(): array
    {
        return [
            '_id' => new FakeMongoInsertedId('nested-object-id'),
        ];
    }
}

final class FakeMongoBsonDocument
{
    /**
     * @return object
     */
    public function bsonSerialize(): object
    {
        return (object) [
            '_id' => new FakeMongoInsertedId('bson-document-id'),
            'email' => 'bson@example.com',
        ];
    }
}

final class FakeMongoFlexibleCollection
{
    public mixed $findOneResult = null;

    public mixed $insertOneResult = null;

    public mixed $replaceOneResult = null;

    public mixed $deleteOneResult = null;

    /** @var array<string, mixed>|null */
    public ?array $lastFilter = null;

    /** @var array<string, mixed>|null */
    public ?array $lastDocument = null;

    public function findOne(array $filter): mixed
    {
        $this->lastFilter = $filter;

        return $this->findOneResult;
    }

    public function insertOne(array $document): mixed
    {
        $this->lastDocument = $document;

        return $this->insertOneResult;
    }

    public function replaceOne(array $filter, array $replacement): mixed
    {
        $this->lastFilter = $filter;
        $this->lastDocument = $replacement;

        return $this->replaceOneResult;
    }

    public function deleteOne(array $filter): mixed
    {
        $this->lastFilter = $filter;

        return $this->deleteOneResult;
    }
}

final class FakeMongoDatabase
{
    /** @var array<string, FakeMongoCollection> */
    public array $collections = [];

    public function selectCollection(string $name): FakeMongoCollection
    {
        return $this->collections[$name] ??= new FakeMongoCollection();
    }
}

#[CoversClass(Mongo::class)]
#[CoversClass(MongoConnection::class)]
#[CoversClass(MongoDbCollection::class)]
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

    public function testFacadeThrowsClearExceptionForUnknownMethod(): void
    {
        $manager = new MongoManager(self::CONNECTION_ALIAS);
        $manager->addConnection(self::CONNECTION_ALIAS, $this->makeConnection());
        Mongo::setManager($manager);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Mongo facade method "foobar" is not supported.');

        Mongo::foobar();
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

    public function testMongoModelSupportsJsonCastingFromStringsAndArrays(): void
    {
        $user = new JsonUserDocument([
            'payload' => '{"tags":["mongo"],"active":true}',
        ]);

        self::assertSame(['tags' => ['mongo'], 'active' => true], $user->payload);
        self::assertSame(['_id' => null, 'payload' => ['tags' => ['mongo'], 'active' => true]], $user->toArray());

        $hydrated = JsonUserDocument::hydrate([
            '_id' => 'doc-2',
            'payload' => ['tags' => ['hydrated'], 'active' => false],
        ]);

        self::assertSame(['tags' => ['hydrated'], 'active' => false], $hydrated->payload);
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

    public function testMongoConnectionCanResolveRealDatabaseCollectionsLazily(): void
    {
        $database = new FakeMongoDatabase();
        $connection = MongoConnection::fromDatabase($database);

        $collection = $connection->collection('users');

        self::assertInstanceOf(MongoDbCollection::class, $collection);
        self::assertSame($collection, $connection->collection('users'));
        self::assertArrayHasKey('users', $database->collections);
    }

    public function testMongoDbCollectionWrapsMongoCollectionOperations(): void
    {
        $native = new FakeMongoCollection();
        $collection = new MongoDbCollection($native);

        $id = $collection->insertOne(['email' => 'native@example.com']);
        $found = $collection->findOne(['_id' => $id]);

        self::assertSame('fake-mongo-id', $id);
        self::assertSame('fake-mongo-id', $found['_id'] ?? null);
        self::assertSame('native@example.com', $found['email'] ?? null);

        self::assertTrue($collection->updateOne(['_id' => $id], ['_id' => $id, 'email' => 'updated@example.com']));
        self::assertSame(['_id' => $id, 'email' => 'updated@example.com'], $native->lastReplacement);
        self::assertFalse($collection->updateOne(['_id' => 'missing'], ['email' => 'nope']));

        self::assertTrue($collection->deleteOne(['_id' => $id]));
        self::assertFalse($collection->deleteOne(['_id' => $id]));
    }

    public function testMongoDbCollectionNormalizesNativeMongoDocumentShapes(): void
    {
        $native = new FakeMongoFlexibleCollection();
        $collection = new MongoDbCollection($native);

        $native->findOneResult = new FakeMongoArrayDocument();
        $document = $collection->findOne(['_id' => 'array-document-id']);

        self::assertSame('array-document-id', $document['_id']);
        self::assertSame('nested-array-id', $document['profile']['_id']);
        self::assertSame('nested-object-id', $document['settings']['_id']);

        $native->findOneResult = new FakeMongoBsonDocument();
        $document = $collection->findOne(['email' => 'bson@example.com']);

        self::assertSame('bson-document-id', $document['_id']);
        self::assertSame('bson@example.com', $document['email']);

        $native->findOneResult = (object) [
            '_id' => new FakeMongoInsertedId('object-document-id'),
            'email' => 'object@example.com',
        ];
        $document = $collection->findOne(['email' => 'object@example.com']);

        self::assertSame('object-document-id', $document['_id']);
        self::assertSame('object@example.com', $document['email']);

        $native->findOneResult = new \ArrayIterator([
            '_id' => new FakeMongoInsertedId('iterator-document-id'),
            'email' => 'iterator@example.com',
        ]);
        $document = $collection->findOne(['email' => 'iterator@example.com']);

        self::assertSame('iterator-document-id', $document['_id']);
        self::assertSame('iterator@example.com', $document['email']);
    }

    public function testMongoDbCollectionConvertsObjectIdFiltersAndWrites(): void
    {
        $native = new FakeMongoFlexibleCollection();
        $native->insertOneResult = new FakeMongoWriteResult(insertedId: new ObjectId('507f1f77bcf86cd799439011'));
        $native->replaceOneResult = new FakeMongoWriteResult(matchedCount: 1);
        $native->deleteOneResult = new FakeMongoWriteResult(deletedCount: 1);
        $collection = new MongoDbCollection($native);

        self::assertSame('507f1f77bcf86cd799439011', $collection->insertOne([
            '_id' => '507f1f77bcf86cd799439011',
            'email' => 'object-id@example.com',
        ]));
        self::assertInstanceOf(ObjectId::class, $native->lastDocument['_id']);

        self::assertTrue($collection->updateOne([
            '_id' => '507f1f77bcf86cd799439011',
        ], [
            '_id' => '507f1f77bcf86cd799439012',
            'email' => 'updated-object-id@example.com',
        ]));
        self::assertInstanceOf(ObjectId::class, $native->lastFilter['_id']);
        self::assertInstanceOf(ObjectId::class, $native->lastDocument['_id']);

        self::assertTrue($collection->deleteOne(['_id' => '507f1f77bcf86cd799439011']));
        self::assertInstanceOf(ObjectId::class, $native->lastFilter['_id']);

        $collection->insertOne([
            '_id' => null,
            'email' => 'generated@example.com',
        ]);
        self::assertArrayNotHasKey('_id', $native->lastDocument);
    }

    public function testMongoDbCollectionRejectsInvalidNativeObjectsAndResults(): void
    {
        try {
            new MongoDbCollection(new class () {
                public function findOne(array $filter): ?array
                {
                    return null;
                }

                public function insertOne(array $document): object
                {
                    return new \stdClass();
                }

                public function replaceOne(array $filter, array $replacement): object
                {
                    return new \stdClass();
                }
            });
            self::fail('Expected missing method exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Mongo collection object must provide deleteOne().', $exception->getMessage());
        }

        $native = new FakeMongoFlexibleCollection();
        $collection = new MongoDbCollection($native);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mongo insertOne() result must provide getInsertedId().');

        $collection->insertOne(['email' => 'invalid-result@example.com']);
    }

    public function testMongoDbCollectionRejectsInvalidDocumentsAndIds(): void
    {
        $native = new FakeMongoFlexibleCollection();
        $collection = new MongoDbCollection($native);

        try {
            $native->findOneResult = 'not-a-document';
            $collection->findOne(['_id' => 'broken']);
            self::fail('Expected invalid document exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Mongo document must normalize to an array.', $exception->getMessage());
        }

        $native->findOneResult = ['_id' => ['not' => 'scalar']];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mongo document _id must be a string, integer, or stringable value.');

        $collection->findOne(['_id' => 'broken-id']);
    }

    public function testMongoDbCollectionRejectsInvalidUpdateAndDeleteResults(): void
    {
        $native = new FakeMongoFlexibleCollection();
        $collection = new MongoDbCollection($native);

        try {
            $collection->updateOne(['_id' => 'broken'], ['email' => 'broken@example.com']);
            self::fail('Expected invalid replace result exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Mongo replaceOne() result must provide getMatchedCount().', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mongo deleteOne() result must provide getDeletedCount().');

        $collection->deleteOne(['_id' => 'broken']);
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
