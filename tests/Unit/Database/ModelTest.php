<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use DateTimeImmutable;
use InvalidArgumentException;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Attributes\Cast;
use Myxa\Database\Attributes\Guarded;
use Myxa\Database\Attributes\Hidden;
use Myxa\Database\Attributes\Internal;
use Myxa\Database\Connection\PdoConnection;
use Myxa\Database\Connection\PdoConnectionConfig;
use Myxa\Database\Model\CastType;
use Myxa\Database\Model\HasBlameable;
use Myxa\Database\Model\HasTimestamps;
use Myxa\Database\Model\Model;
use Myxa\Database\Model\ModelNotFoundException;
use Myxa\Database\Model\ModelQuery;
use PDO;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class User extends Model
{
    use HasTimestamps;

    protected string $table = 'users';

    protected string $email = '';

    protected string $status = '';

    public function profile(): ModelQuery
    {
        return $this->hasOne(Profile::class);
    }

    public function posts(): ModelQuery
    {
        return $this->hasMany(Post::class);
    }
}

final class RemoteUser extends Model
{
    use HasTimestamps;

    protected string $table = 'users';

    protected ?string $connection = 'model-remote';

    protected string $email = '';

    protected string $status = '';
}

final class SecureUser extends Model
{
    use HasTimestamps;

    protected string $table = 'users';

    protected string $email = '';

    protected string $status = '';

    #[Guarded]
    #[Hidden]
    protected ?string $password_hash = null;
}

final class CastedUser extends Model
{
    protected string $table = 'users';

    protected string $email = '';

    protected string $status = '';

    #[Cast(CastType::DateTimeImmutable, format: DATE_ATOM)]
    protected ?DateTimeImmutable $created_at = null;

    #[Cast(CastType::DateTimeImmutable, format: DATE_ATOM)]
    protected ?DateTimeImmutable $updated_at = null;
}

final class InternalPropertyUser extends Model
{
    protected string $table = 'users';

    protected string $email = '';

    protected string $status = '';

    #[Internal]
    protected string $helperLabel = 'draft';

    public function helperLabel(): string
    {
        return $this->helperLabel;
    }
}

final class Profile extends Model
{
    use HasTimestamps;

    protected string $table = 'profiles';

    protected ?int $user_id = null;

    protected string $bio = '';

    public function user(): ModelQuery
    {
        return $this->belongsTo(User::class);
    }
}

final class Post extends Model
{
    use HasTimestamps;

    protected string $table = 'posts';

    protected ?int $user_id = null;

    protected string $title = '';

    public function user(): ModelQuery
    {
        return $this->belongsTo(User::class);
    }
}

final class ExternalUser extends Model
{
    protected string $table = 'external_users';

    protected string $primaryKey = 'uuid';

    protected ?string $uuid = null;

    protected string $email = '';
}

final class AuditedUser extends Model
{
    use HasTimestamps;
    use HasBlameable;

    protected string $table = 'audited_users';

    protected string $email = '';

    protected string $status = '';
}

#[CoversClass(Model::class)]
#[CoversClass(ModelQuery::class)]
#[CoversClass(ModelNotFoundException::class)]
final class ModelTest extends TestCase
{
    private const string CONNECTION_ALIAS = 'model-test';

    private const string REMOTE_CONNECTION_ALIAS = 'model-remote';

    protected function setUp(): void
    {
        PdoConnection::register(self::CONNECTION_ALIAS, $this->makeInMemoryConnection(), true);
        PdoConnection::register(self::REMOTE_CONNECTION_ALIAS, $this->makeInMemoryConnection(), true);
        Model::setManager($this->makeManager());
    }

    protected function tearDown(): void
    {
        AuditedUser::clearBlameResolver();
        Model::clearManager();
        PdoConnection::unregister(self::CONNECTION_ALIAS);
        PdoConnection::unregister(self::REMOTE_CONNECTION_ALIAS);
    }

    public function testFindAndQueryHydrateModels(): void
    {
        $this->makeManager()->insert(
            'INSERT INTO users (email, status, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['john@example.com', 'active', '2026-04-01T10:00:00+00:00', '2026-04-01T10:00:00+00:00'],
        );
        $this->makeManager()->insert(
            'INSERT INTO users (email, status, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['jane@example.com', 'active', '2026-04-01T10:05:00+00:00', '2026-04-01T10:05:00+00:00'],
        );

        $user = User::find(1);

        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->exists());
        self::assertSame(1, (int) $user->getKey());
        self::assertSame('john@example.com', $user->email);
        self::assertSame(1, (int) $user->toArray()['id']);
        self::assertSame('john@example.com', $user->toArray()['email']);
        self::assertSame('active', $user->toArray()['status']);

        $users = User::query()
            ->where('status', '=', 'active')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get();

        self::assertCount(1, $users);
        self::assertSame('jane@example.com', $users[0]->email);
    }

    public function testCreateAndSavePersistModels(): void
    {
        $user = User::create(['email' => 'new@example.com', 'status' => 'pending']);

        self::assertTrue($user->exists());
        self::assertNotNull($user->getKey());
        self::assertNotNull($user->created_at);
        self::assertNotNull($user->updated_at);
        self::assertArrayNotHasKey('createdAtColumn', $user->toArray());
        self::assertArrayNotHasKey('updatedAtColumn', $user->toArray());

        $user->status = 'active';

        self::assertTrue($user->save());
        self::assertSame(
            'active',
            $this->makeManager()->select('SELECT status FROM users WHERE id = ?', [$user->getKey()])[0]['status'],
        );
    }

    public function testFillSkipsGuardedAttributes(): void
    {
        $user = new SecureUser([
            'email' => 'safe@example.com',
            'status' => 'active',
            'password_hash' => 'incoming-hash',
        ]);

        self::assertSame('safe@example.com', $user->email);
        self::assertSame('active', $user->status);
        self::assertNull($user->password_hash);

        $user->fill([
            'status' => 'archived',
            'password_hash' => 'updated-hash',
        ]);

        self::assertSame('archived', $user->status);
        self::assertNull($user->password_hash);
    }

    public function testFillRejectsUnknownAttributes(): void
    {
        $user = new User([
            'email' => 'safe@example.com',
            'status' => 'active',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Cannot mass-assign unknown attribute "nickname" on model %s.',
            User::class,
        ));

        $user->fill(['nickname' => 'Bird']);
    }

    public function testHiddenAttributesAreOmittedFromArrayOutput(): void
    {
        $user = new SecureUser([
            'email' => 'hidden@example.com',
            'status' => 'active',
        ]);
        $user->password_hash = 'stored-hash';

        $attributes = $user->toArray();

        self::assertArrayNotHasKey('password_hash', $attributes);
        self::assertArrayNotHasKey('id', $attributes);
        self::assertSame('hidden@example.com', $attributes['email']);
        self::assertSame('active', $attributes['status']);
        self::assertNull($attributes['created_at']);
        self::assertNull($attributes['updated_at']);
    }

    public function testTrustedCodeCanSetGuardedAttributesDirectlyAndPersistThem(): void
    {
        $user = new SecureUser([
            'email' => 'persist-hidden@example.com',
            'status' => 'active',
        ]);

        $user->password_hash = 'trusted-hash';

        self::assertTrue($user->save());
        self::assertSame(
            'trusted-hash',
            $this->makeManager()->select('SELECT password_hash FROM users WHERE id = ?', [$user->getKey()])[0]['password_hash'],
        );
        self::assertArrayNotHasKey('password_hash', $user->toArray());
    }

    public function testSettingUnknownAttributesThrowsException(): void
    {
        $user = new User([
            'email' => 'strict@example.com',
            'status' => 'active',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Cannot set unknown attribute "nickname" on model %s.',
            User::class,
        ));

        $user->setAttribute('nickname', 'Bird');
    }

    public function testInternalPropertiesStayOutsideModelFieldLogic(): void
    {
        $user = new InternalPropertyUser([
            'email' => 'helper@example.com',
            'status' => 'active',
        ]);

        self::assertSame('draft', $user->helperLabel());
        self::assertArrayNotHasKey('helperLabel', $user->toArray());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Cannot mass-assign unknown attribute "helperLabel" on model %s.',
            InternalPropertyUser::class,
        ));

        $user->fill(['helperLabel' => 'updated']);
    }

    public function testHydrationBypassesGuardedFillForPersistedAttributes(): void
    {
        $this->makeManager()->insert(
            'INSERT INTO users (email, status, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['hydrated@example.com', 'active', 'stored-hash', '2026-04-01T10:00:00+00:00', '2026-04-01T10:00:00+00:00'],
        );

        $user = SecureUser::findOrFail(1);

        self::assertSame('hydrated@example.com', $user->email);
        self::assertSame('stored-hash', $user->password_hash);
        self::assertArrayNotHasKey('password_hash', $user->toArray());
    }

    public function testCastedDateTimePropertiesHydrateToObjectsAndSerializeBackToStrings(): void
    {
        $this->makeManager()->insert(
            'INSERT INTO users (email, status, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['casted@example.com', 'active', '2026-04-01T10:00:00+00:00', '2026-04-01T10:05:00+00:00'],
        );

        $user = CastedUser::findOrFail(1);

        self::assertInstanceOf(DateTimeImmutable::class, $user->created_at);
        self::assertInstanceOf(DateTimeImmutable::class, $user->updated_at);
        self::assertSame('2026-04-01T10:00:00+00:00', $user->created_at?->format(DATE_ATOM));
        $attributes = $user->toArray();

        self::assertSame('casted@example.com', $attributes['email']);
        self::assertSame('active', $attributes['status']);
        self::assertSame('2026-04-01T10:00:00+00:00', $attributes['created_at']);
        self::assertSame('2026-04-01T10:05:00+00:00', $attributes['updated_at']);
        self::assertSame(1, $attributes['id']);
        self::assertArrayNotHasKey('password_hash', $attributes);
    }

    public function testCastedDateTimePropertiesPersistUsingDeclaredFormat(): void
    {
        $user = new CastedUser([
            'email' => 'persist-cast@example.com',
            'status' => 'active',
            'created_at' => '2026-04-01T11:00:00+00:00',
            'updated_at' => '2026-04-01T11:05:00+00:00',
        ]);

        self::assertInstanceOf(DateTimeImmutable::class, $user->created_at);
        self::assertTrue($user->save());
        self::assertSame(
            [
                'created_at' => '2026-04-01T11:00:00+00:00',
                'updated_at' => '2026-04-01T11:05:00+00:00',
            ],
            $this->makeManager()->select(
                'SELECT created_at, updated_at FROM users WHERE id = ?',
                [$user->getKey()],
            )[0],
        );
    }

    public function testInvalidDateTimeCastInputThrowsHelpfulException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Cannot cast value "not-a-date" for property "created_at" on model %s to %s.',
            CastedUser::class,
            DateTimeImmutable::class,
        ));

        new CastedUser([
            'email' => 'broken@example.com',
            'status' => 'active',
            'created_at' => 'not-a-date',
        ]);
    }

    public function testToJsonEncodesSerializedAttributes(): void
    {
        $user = new SecureUser([
            'email' => 'json@example.com',
            'status' => 'active',
        ]);
        $user->password_hash = 'stored-hash';

        self::assertSame(
            '{"email":"json@example.com","status":"active","created_at":null,"updated_at":null}',
            $user->toJson(),
        );
    }

    public function testToJsonThrowsWhenEncodingFails(): void
    {
        $user = new User([
            'email' => "\xB1\x31",
            'status' => 'active',
        ]);

        $this->expectException(JsonException::class);

        $user->toJson();
    }

    public function testRefreshReloadsPersistedState(): void
    {
        $user = User::create(['email' => 'refresh@example.com', 'status' => 'draft']);

        $this->makeManager()->update(
            'UPDATE users SET status = ? WHERE id = ?',
            ['archived', $user->getKey()],
        );

        $user->refresh();

        self::assertSame('archived', $user->status);
    }

    public function testDeleteRemovesPersistedModel(): void
    {
        $user = User::create(['email' => 'delete@example.com', 'status' => 'inactive']);

        self::assertTrue($user->delete());
        self::assertFalse($user->exists());
        self::assertNull($user->getKey());
        self::assertSame(
            0,
            (int) $this->makeManager()->select('SELECT COUNT(*) AS total FROM users')[0]['total'],
        );
    }

    public function testReadOnlyModelsCannotBeSavedOrDeleted(): void
    {
        $newUser = (new User(['email' => 'readonly@example.com', 'status' => 'draft']))->setReadOnly();

        self::assertFalse($newUser->save());

        $persisted = User::create(['email' => 'persisted@example.com', 'status' => 'active']);
        $persisted->setReadOnly();

        self::assertFalse($persisted->delete());
    }

    public function testCloningProducesANewUnsavedModel(): void
    {
        $user = User::create(['email' => 'clone@example.com', 'status' => 'active']);

        $copy = clone $user;
        $copy->email = 'clone-copy@example.com';

        self::assertNull($copy->getKey());
        self::assertFalse($copy->exists());
        self::assertTrue($copy->save());
        self::assertNotSame($user->getKey(), $copy->getKey());
    }

    public function testRelationsResolveHasOneHasManyAndBelongsTo(): void
    {
        $user = User::create(['email' => 'relations@example.com', 'status' => 'active']);
        Profile::create(['user_id' => $user->getKey(), 'bio' => 'About user']);
        Post::create(['user_id' => $user->getKey(), 'title' => 'First']);
        Post::create(['user_id' => $user->getKey(), 'title' => 'Second']);

        $profile = $user->profile()->firstOrFail();
        $posts = $user->posts()->orderBy('title')->get();

        self::assertInstanceOf(Profile::class, $profile);
        self::assertSame('About user', $profile->bio);
        self::assertCount(2, $posts);
        self::assertSame('First', $posts[0]->title);
        self::assertSame('relations@example.com', $profile->user()->firstOrFail()->email);
        self::assertSame('relations@example.com', $posts[0]->user()->firstOrFail()->email);
    }

    public function testCustomPrimaryKeyConstantsSupportManualIdentifiers(): void
    {
        $user = ExternalUser::create([
            'uuid' => 'ext_123',
            'email' => 'external@example.com',
        ]);

        self::assertSame('ext_123', $user->getKey());
        self::assertNull($user->toArray()['created_at'] ?? null);
        self::assertSame(
            'external@example.com',
            ExternalUser::findOrFail('ext_123')->email,
        );
    }

    public function testBlameableTraitTracksCreatorAndUpdater(): void
    {
        AuditedUser::setBlameResolver(static fn (Model $model): int => 7);

        $user = AuditedUser::create(['email' => 'audit@example.com', 'status' => 'draft']);

        self::assertSame(7, $user->created_by);
        self::assertSame(7, $user->updated_by);
        self::assertArrayNotHasKey('createdByColumn', $user->toArray());
        self::assertArrayNotHasKey('updatedByColumn', $user->toArray());

        AuditedUser::setBlameResolver(static fn (Model $model): int => 9);
        $user->status = 'published';

        self::assertTrue($user->save());
        self::assertSame(7, $user->created_by);
        self::assertSame(9, $user->updated_by);
        self::assertSame(
            [
                'created_by' => 7,
                'updated_by' => 9,
            ],
            $this->makeManager()->select(
                'SELECT created_by, updated_by FROM audited_users WHERE id = ?',
                [$user->getKey()],
            )[0],
        );
    }

    public function testFindOrFailThrowsModelNotFoundException(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage(sprintf('No record was found for model %s with key "missing".', ExternalUser::class));

        ExternalUser::findOrFail('missing');
    }

    public function testModelsCanUseDifferentConnectionAliases(): void
    {
        $this->makeManager()->insert(
            'INSERT INTO users (email, status, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['local@example.com', 'active', '2026-04-01T10:00:00+00:00', '2026-04-01T10:00:00+00:00'],
        );
        $this->makeManager()->insert(
            'INSERT INTO users (email, status, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['remote@example.com', 'active', '2026-04-01T10:10:00+00:00', '2026-04-01T10:10:00+00:00'],
            self::REMOTE_CONNECTION_ALIAS,
        );

        $local = User::findOrFail(1);
        $remote = RemoteUser::findOrFail(1);

        self::assertSame('local@example.com', $local->email);
        self::assertSame('remote@example.com', $remote->email);

        $remote->status = 'archived';
        self::assertTrue($remote->save());

        self::assertSame(
            'active',
            $this->makeManager()->select('SELECT status FROM users WHERE id = ?', [1])[0]['status'],
        );
        self::assertSame(
            'archived',
            $this->makeManager()->select(
                'SELECT status FROM users WHERE id = ?',
                [1],
                self::REMOTE_CONNECTION_ALIAS,
            )[0]['status'],
        );
    }

    private function makeManager(): DatabaseManager
    {
        return new DatabaseManager(self::CONNECTION_ALIAS);
    }

    private function makeInMemoryConnection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE users ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'email TEXT NOT NULL, '
            . 'status TEXT NOT NULL, '
            . 'password_hash TEXT NULL, '
            . 'created_at TEXT NULL, '
            . 'updated_at TEXT NULL'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE profiles ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'user_id INTEGER NOT NULL, '
            . 'bio TEXT NOT NULL, '
            . 'created_at TEXT NULL, '
            . 'updated_at TEXT NULL'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE posts ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'user_id INTEGER NOT NULL, '
            . 'title TEXT NOT NULL, '
            . 'created_at TEXT NULL, '
            . 'updated_at TEXT NULL'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE external_users ('
            . 'uuid TEXT PRIMARY KEY, '
            . 'email TEXT NOT NULL'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE audited_users ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'email TEXT NOT NULL, '
            . 'status TEXT NOT NULL, '
            . 'created_at TEXT NULL, '
            . 'updated_at TEXT NULL, '
            . 'created_by INTEGER NULL, '
            . 'updated_by INTEGER NULL'
            . ')',
        );

        $connection = new PdoConnection(
            new PdoConnectionConfig(
                engine: 'mysql',
                database: 'placeholder',
                host: '127.0.0.1',
            ),
        );

        $pdoProperty = new ReflectionProperty(PdoConnection::class, 'pdo');
        $pdoProperty->setValue($connection, $pdo);

        return $connection;
    }
}
