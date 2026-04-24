<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use DateTimeImmutable;
use InvalidArgumentException;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Attributes\Cast;
use Myxa\Database\Attributes\Guarded;
use Myxa\Database\Attributes\Hidden;
use Myxa\Database\Attributes\Hook;
use Myxa\Database\Attributes\Internal;
use Myxa\Database\Model\BelongsToRelation;
use Myxa\Database\Connection\PdoConnection;
use Myxa\Database\Connection\PdoConnectionConfig;
use Myxa\Database\Model\CastType;
use Myxa\Database\Model\HasBlameable;
use Myxa\Database\Model\HasManyRelation;
use Myxa\Database\Model\HasOneRelation;
use Myxa\Database\Model\HasTimestamps;
use Myxa\Database\Model\HookEvent;
use Myxa\Database\Model\Model;
use Myxa\Database\Model\Exceptions\ModelNotFoundException;
use Myxa\Database\Model\ModelMetadata;
use Myxa\Database\Model\ModelQuery;
use Myxa\Database\Model\ModelValueCaster;
use Myxa\Database\Model\Relation;
use PDO;
use JsonException;
use LogicException;
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

final class JsonUser extends Model
{
    protected string $table = 'users';

    protected string $email = '';

    protected string $status = '';

    #[Cast(CastType::Json)]
    protected ?array $meta = null;
}

final class MutableDateUser extends Model
{
    protected string $table = 'users';

    protected string $email = '';

    protected string $status = '';

    #[Cast(CastType::DateTime, format: DATE_ATOM)]
    protected ?\DateTime $created_at = null;
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

    public function comments(): ModelQuery
    {
        return $this->hasMany(Comment::class);
    }
}

final class Comment extends Model
{
    use HasTimestamps;

    protected string $table = 'comments';

    protected ?int $post_id = null;

    protected string $body = '';

    public function post(): ModelQuery
    {
        return $this->belongsTo(Post::class);
    }
}

final class BrokenRelationUser extends Model
{
    protected string $table = 'users';

    protected string $email = '';

    protected string $status = '';

    public function invalidRelation(): string
    {
        return 'invalid';
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

final class ObservedUser extends Model
{
    protected string $table = 'users';

    protected string $email = '';

    protected string $status = '';

    #[Internal]
    public array $hookLog = [];

    #[Internal]
    public array $hookOriginal = [];

    #[Internal]
    public array $hookChanges = [];

    #[Internal]
    public array $hookDirty = [];

    #[Hook(HookEvent::BeforeSave)]
    protected function normalizeEmailBeforeSave(): void
    {
        $this->hookLog[] = 'before_save';
        $this->email = strtolower(trim($this->email));
    }

    #[Hook(HookEvent::AfterSave)]
    protected function markAfterSave(): void
    {
        $this->hookLog[] = 'after_save';
        $this->status = sprintf('%s:saved', $this->status);
    }

    #[Hook(HookEvent::BeforeUpdate)]
    protected function markBeforeUpdate(): void
    {
        $this->hookLog[] = 'before_update';
        $this->hookOriginal = $this->getOriginal();
        $this->hookDirty = $this->getDirty();
        $this->status = sprintf('%s:updating', $this->status);
    }

    #[Hook(HookEvent::AfterUpdate)]
    protected function markAfterUpdate(): void
    {
        $this->hookLog[] = 'after_update';
        $this->hookOriginal = $this->getOriginal();
        $this->hookChanges = $this->getChanges();
    }

    #[Hook(HookEvent::BeforeDelete)]
    protected function markBeforeDelete(): void
    {
        $this->hookLog[] = 'before_delete';
    }

    #[Hook(HookEvent::AfterDelete)]
    protected function markAfterDelete(): void
    {
        $this->hookLog[] = 'after_delete';
        $this->hookOriginal = $this->getOriginal();
        $this->hookChanges = $this->getChanges();
    }
}

#[CoversClass(Model::class)]
#[CoversClass(ModelQuery::class)]
#[CoversClass(ModelNotFoundException::class)]
#[CoversClass(BelongsToRelation::class)]
#[CoversClass(Cast::class)]
#[CoversClass(HasBlameable::class)]
#[CoversClass(HasManyRelation::class)]
#[CoversClass(HasOneRelation::class)]
#[CoversClass(HasTimestamps::class)]
#[CoversClass(Hook::class)]
#[CoversClass(ModelMetadata::class)]
#[CoversClass(ModelValueCaster::class)]
#[CoversClass(Relation::class)]
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

    public function testModelMakeAllAndRelationHelpers(): void
    {
        $made = User::make(['email' => 'made@example.com', 'status' => 'draft']);

        self::assertFalse($made->exists());
        self::assertTrue($made->isReadOnly() === false);
        self::assertFalse(isset($made->profile));
        $made->setRelation('profile', 'loaded');
        self::assertTrue($made->relationLoaded('profile'));
        self::assertSame('loaded', $made->getRelation('profile'));
        self::assertTrue(isset($made->profile));

        User::create(['email' => 'all-a@example.com', 'status' => 'active']);
        User::create(['email' => 'all-b@example.com', 'status' => 'archived']);

        self::assertCount(1, User::all(limit: 1));
        self::assertCount(1, User::all(limit: 1, offset: 1));
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

    public function testJsonCastHydratesToArrayAndSerializesForOutput(): void
    {
        $this->makeManager()->insert(
            'INSERT INTO users (email, status, meta) VALUES (?, ?, ?)',
            ['json-casted@example.com', 'active', '{"notifications":{"email":true},"tags":["vip","beta"]}'],
        );

        $user = JsonUser::findOrFail(1);

        self::assertSame(
            [
                'notifications' => ['email' => true],
                'tags' => ['vip', 'beta'],
            ],
            $user->meta,
        );
        self::assertSame($user->meta, $user->toArray()['meta']);
        self::assertStringContainsString('"meta":{"notifications":{"email":true},"tags":["vip","beta"]}', $user->toJson());
    }

    public function testJsonCastPersistsArraysAsJsonStrings(): void
    {
        $user = new JsonUser([
            'email' => 'persist-json@example.com',
            'status' => 'active',
            'meta' => [
                'notifications' => ['email' => true, 'sms' => false],
                'tags' => ['pro'],
            ],
        ]);

        self::assertTrue($user->save());
        self::assertSame(
            '{"notifications":{"email":true,"sms":false},"tags":["pro"]}',
            $this->makeManager()->select('SELECT meta FROM users WHERE id = ?', [$user->getKey()])[0]['meta'],
        );
    }

    public function testMutableDateCastAcceptsDateTimeInterfacesAndRejectsInvalidTypes(): void
    {
        $immutable = new DateTimeImmutable('2026-04-01T12:00:00+00:00');

        $user = new MutableDateUser([
            'email' => 'mutable-date@example.com',
            'status' => 'active',
            'created_at' => $immutable,
        ]);

        self::assertInstanceOf(\DateTime::class, $user->created_at);
        self::assertSame('2026-04-01T12:00:00+00:00', $user->created_at?->format(DATE_ATOM));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Cannot cast non-string value for property "created_at" on model %s to %s.',
            MutableDateUser::class,
            \DateTime::class,
        ));

        new MutableDateUser([
            'email' => 'broken-mutable-date@example.com',
            'status' => 'active',
            'created_at' => 123,
        ]);
    }

    public function testJsonCastRejectsNonStringValuesAndUnserializableStorageValues(): void
    {
        try {
            new JsonUser([
                'email' => 'broken-json-type@example.com',
                'status' => 'active',
                'meta' => 123,
            ]);
            self::fail('Expected invalid JSON cast type exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                sprintf('Cannot cast non-string value for property "meta" on model %s to JSON.', JsonUser::class),
                $exception->getMessage(),
            );
        }

        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        $user = new JsonUser([
            'email' => 'broken-json-storage@example.com',
            'status' => 'active',
            'meta' => ['resource' => $resource],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Cannot serialize value for property "meta" on model %s as JSON.',
            JsonUser::class,
        ));

        $user->save();
    }

    public function testInvalidJsonCastInputThrowsHelpfulException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Cannot cast value "%s" for property "meta" on model %s to JSON.',
            '{invalid',
            JsonUser::class,
        ));

        new JsonUser([
            'email' => 'broken-json@example.com',
            'status' => 'active',
            'meta' => '{invalid',
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

    public function testModelTracksOriginalDirtyAndChangedAttributes(): void
    {
        $created = User::create(['email' => 'dirty@example.com', 'status' => 'draft']);
        $user = User::findOrFail((int) $created->getKey());

        self::assertSame(
            [
                'email' => 'dirty@example.com',
                'status' => 'draft',
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'id' => $user->getKey(),
            ],
            $user->getOriginal(),
        );
        self::assertFalse($user->isDirty());
        self::assertFalse($user->wasChanged('status'));

        $user->status = 'published';

        self::assertTrue($user->isDirty());
        self::assertTrue($user->isDirty('status'));
        self::assertSame(['status' => 'published'], $user->getDirty());

        self::assertTrue($user->save());
        self::assertTrue($user->wasChanged());
        self::assertTrue($user->wasChanged('status'));
        self::assertSame(['status' => 'published'], $user->getChanges());
        self::assertSame('published', $user->getOriginal('status'));
        self::assertFalse($user->isDirty());
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

        self::assertTrue($newUser->isReadOnly());
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

    public function testModelCanUnsetDeclaredAndHydratedAttributes(): void
    {
        $user = SecureUser::create(['email' => 'unset@example.com', 'status' => 'active']);
        $user->password_hash = 'secret';

        unset($user->password_hash);
        self::assertNull($user->password_hash);

        $external = ExternalUser::hydrate(['uuid' => 'abc-1', 'email' => 'external@example.com']);
        unset($external->uuid);
        self::assertNull($external->getKey());
    }

    public function testModelRejectsInvalidRelatedModelClass(): void
    {
        $user = new class extends Model
        {
            protected string $table = 'users';

            protected string $email = '';

            protected string $status = '';

            public function invalid(): \Myxa\Database\Model\Relation
            {
                /** @phpstan-ignore-next-line */
                return $this->hasOne(\stdClass::class);
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Related model "%s" must extend %s.',
            \stdClass::class,
            Model::class,
        ));

        $user->invalid();
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
        self::assertInstanceOf(HasManyRelation::class, $user->posts());
    }

    public function testQueryCanEagerLoadBelongsToRelations(): void
    {
        $user = User::create(['email' => 'belongs-to@example.com', 'status' => 'active']);
        Profile::create(['user_id' => $user->getKey(), 'bio' => 'Belongs to profile']);

        $profiles = Profile::query()
            ->with('user')
            ->orderBy('id')
            ->get();

        self::assertCount(1, $profiles);
        self::assertInstanceOf(User::class, $profiles[0]->getRelation('user'));
        self::assertSame('belongs-to@example.com', $profiles[0]->getRelation('user')?->email);
        self::assertInstanceOf(BelongsToRelation::class, $profiles[0]->user());
    }

    public function testQueryCanEagerLoadNestedRelations(): void
    {
        $firstUser = User::create(['email' => 'nested-a@example.com', 'status' => 'active']);
        $secondUser = User::create(['email' => 'nested-b@example.com', 'status' => 'active']);
        Profile::create(['user_id' => $firstUser->getKey(), 'bio' => 'Primary profile']);
        $firstPost = Post::create(['user_id' => $firstUser->getKey(), 'title' => 'First post']);
        $secondPost = Post::create(['user_id' => $firstUser->getKey(), 'title' => 'Second post']);
        $thirdPost = Post::create(['user_id' => $secondUser->getKey(), 'title' => 'Third post']);
        Comment::create(['post_id' => $firstPost->getKey(), 'body' => 'Comment one']);
        Comment::create(['post_id' => $firstPost->getKey(), 'body' => 'Comment two']);
        Comment::create(['post_id' => $thirdPost->getKey(), 'body' => 'Comment three']);

        $users = User::query()
            ->with('profile', 'posts.comments')
            ->orderBy('id')
            ->get();

        self::assertCount(2, $users);
        self::assertInstanceOf(Profile::class, $users[0]->getRelation('profile'));
        self::assertSame('Primary profile', $users[0]->getRelation('profile')?->bio);
        self::assertCount(2, $users[0]->getRelation('posts'));
        self::assertCount(1, $users[1]->getRelation('posts'));
        self::assertSame('Comment one', $users[0]->getRelation('posts')[0]->getRelation('comments')[0]->body);
        self::assertSame('Comment three', $users[1]->getRelation('posts')[0]->getRelation('comments')[0]->body);

        $payload = $users[0]->toArray();

        self::assertArrayHasKey('profile', $payload);
        self::assertArrayHasKey('posts', $payload);
        self::assertSame('Primary profile', $payload['profile']['bio']);
        self::assertSame('Comment two', $payload['posts'][0]['comments'][1]['body']);
    }

    public function testModelQuerySupportsJoins(): void
    {
        $user = User::create(['email' => 'join@example.com', 'status' => 'active']);
        Profile::create(['user_id' => $user->getKey(), 'bio' => 'Joined']);

        $users = User::query()
            ->join('profiles', 'profiles.user_id', '=', 'users.id')
            ->where('profiles.bio', '=', 'Joined')
            ->get();

        self::assertCount(1, $users);
        self::assertSame('join@example.com', $users[0]->email);
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

    public function testTimestampAndBlameableTraitsValidateMetadataAndResolverResults(): void
    {
        AuditedUser::setBlameResolver(static fn (Model $model): null => null);
        $unblamed = AuditedUser::create(['email' => 'no-blame@example.com', 'status' => 'draft']);

        self::assertNull($unblamed->created_by);
        self::assertNull($unblamed->updated_by);

        AuditedUser::setBlameResolver(static fn (Model $model): object => new \stdClass());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Blame resolver must return an int, string, or null.');

        AuditedUser::create(['email' => 'bad-blame@example.com', 'status' => 'draft']);
    }

    public function testTimestampMetadataCannotBeEmpty(): void
    {
        $user = new User(['email' => 'bad-timestamp@example.com', 'status' => 'draft']);
        (new ReflectionProperty(User::class, 'createdAtColumn'))->setValue($user, ' ');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Timestamp metadata property "createdAtColumn" cannot be empty.');

        $user->save();
    }

    public function testBlameableMetadataCannotBeEmpty(): void
    {
        AuditedUser::setBlameResolver(static fn (Model $model): int => 1);
        $user = new AuditedUser(['email' => 'bad-blame-column@example.com', 'status' => 'draft']);
        (new ReflectionProperty(AuditedUser::class, 'createdByColumn'))->setValue($user, ' ');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Blameable metadata property "createdByColumn" cannot be empty.');

        $user->save();
    }

    public function testHookMethodsRunBeforeAndAfterSave(): void
    {
        $user = new ObservedUser([
            'email' => '  MIXED@Example.COM ',
            'status' => 'draft',
        ]);

        self::assertTrue($user->save());
        self::assertSame(['before_save', 'after_save'], $user->hookLog);
        self::assertSame('mixed@example.com', $user->email);
        self::assertSame('draft:saved', $user->status);
        self::assertSame(
            [
                'email' => 'mixed@example.com',
                'status' => 'draft',
            ],
            $this->makeManager()->select(
                'SELECT email, status FROM users WHERE id = ?',
                [$user->getKey()],
            )[0],
        );
    }

    public function testUpdateSaveRunsSaveAndUpdateHooks(): void
    {
        $user = ObservedUser::create([
            'email' => 'first@example.com',
            'status' => 'draft',
        ]);

        self::assertSame(['before_save', 'after_save'], $user->hookLog);

        $user->hookLog = [];
        $user->email = ' SECOND@example.com ';
        $user->status = 'published';

        self::assertTrue($user->save());
        self::assertSame(['before_save', 'before_update', 'after_update', 'after_save'], $user->hookLog);
        self::assertSame('second@example.com', $user->email);
        self::assertSame('published:updating:saved', $user->status);
        self::assertSame(
            [
                'email' => 'first@example.com',
                'status' => 'draft:saved',
                'id' => $user->getKey(),
            ],
            array_intersect_key($user->hookOriginal, array_flip(['id', 'email', 'status'])),
        );
        self::assertSame(
            [
                'email' => 'second@example.com',
                'status' => 'published',
            ],
            $user->hookDirty,
        );
        self::assertSame(
            [
                'email' => 'second@example.com',
                'status' => 'published:updating',
            ],
            $user->hookChanges,
        );
        self::assertSame(
            [
                'email' => 'second@example.com',
                'status' => 'published:updating',
            ],
            $this->makeManager()->select(
                'SELECT email, status FROM users WHERE id = ?',
                [$user->getKey()],
            )[0],
        );
    }

    public function testDeleteRunsDeleteHooks(): void
    {
        $user = ObservedUser::create([
            'email' => 'delete-hooks@example.com',
            'status' => 'draft',
        ]);

        $user->hookLog = [];

        self::assertTrue($user->delete());
        self::assertSame(['before_delete', 'after_delete'], $user->hookLog);
        self::assertSame(
            [
                'email' => 'delete-hooks@example.com',
                'status' => 'draft:saved',
                'id' => $user->getOriginal('id'),
            ],
            array_intersect_key($user->hookOriginal, array_flip(['id', 'email', 'status'])),
        );
        self::assertSame($user->hookOriginal, $user->hookChanges);
        self::assertFalse($user->exists());
        self::assertNull($user->getKey());
    }

    public function testFindOrFailThrowsModelNotFoundException(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage(sprintf('No record was found for model %s with key "missing".', ExternalUser::class));

        ExternalUser::findOrFail('missing');
    }

    public function testModelNotFoundExceptionExposesContext(): void
    {
        $exception = ModelNotFoundException::forModel(User::class);

        self::assertSame(User::class, $exception->getModelClass());
        self::assertNull($exception->getKey());

        $keyed = ModelNotFoundException::forKey(ExternalUser::class, 'missing');

        self::assertSame(ExternalUser::class, $keyed->getModelClass());
        self::assertSame('missing', $keyed->getKey());
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

    public function testModelQueryExposesSqlBindingsAndAdditionalHelpers(): void
    {
        User::create(['email' => 'helper-a@example.com', 'status' => 'active']);
        User::create(['email' => 'helper-b@example.com', 'status' => 'pending']);

        $joinedQuery = User::query()
            ->select('users.email', 'users.status')
            ->whereBetween('users.id', 1, 2)
            ->whereIn('status', ['active', 'pending'])
            ->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')
            ->orderBy('users.id');

        self::assertStringContainsString('LEFT JOIN', $joinedQuery->toSql());
        self::assertSame([1, 2, 'active', 'pending'], $joinedQuery->getBindings());

        $query = User::query()
            ->whereKey(1)
            ->orderBy('id');

        self::assertTrue($query->exists());
        self::assertSame('helper-a@example.com', $query->firstOrFail()->email);
    }

    public function testModelQueryCursorStreamsHydratedModels(): void
    {
        User::create(['email' => 'cursor-a@example.com', 'status' => 'active']);
        User::create(['email' => 'cursor-b@example.com', 'status' => 'pending']);
        User::create(['email' => 'cursor-c@example.com', 'status' => 'active']);

        $cursor = User::query()
            ->where('status', '=', 'active')
            ->orderBy('id')
            ->cursor();

        self::assertInstanceOf(\Generator::class, $cursor);

        $emails = [];
        foreach ($cursor as $user) {
            self::assertInstanceOf(User::class, $user);
            $emails[] = $user->email;
        }

        self::assertSame(['cursor-a@example.com', 'cursor-c@example.com'], $emails);

        $limited = [];
        foreach (User::cursor(limit: 1) as $user) {
            $limited[] = $user->email;
        }

        self::assertSame(['cursor-a@example.com'], $limited);
    }

    public function testModelQueryChunksHydratedModelsAndCanStopEarly(): void
    {
        foreach (range(1, 5) as $index) {
            User::create([
                'email' => sprintf('chunk-%d@example.com', $index),
                'status' => 'active',
            ]);
        }

        $chunks = [];
        $completed = User::query()
            ->orderBy('id')
            ->chunk(2, function (array $users, int $page) use (&$chunks): void {
                $chunks[] = [
                    'page' => $page,
                    'emails' => array_map(static fn ($user): string => $user->email, $users),
                ];
            });

        self::assertTrue($completed);
        self::assertSame(
            [
                ['page' => 1, 'emails' => ['chunk-1@example.com', 'chunk-2@example.com']],
                ['page' => 2, 'emails' => ['chunk-3@example.com', 'chunk-4@example.com']],
                ['page' => 3, 'emails' => ['chunk-5@example.com']],
            ],
            $chunks,
        );

        $visitedPages = [];
        $stopped = User::chunk(2, function (array $users, int $page) use (&$visitedPages): bool {
            $visitedPages[] = $page;

            return false;
        });

        self::assertFalse($stopped);
        self::assertSame([1], $visitedPages);
    }

    public function testModelQueryChunkRejectsInvalidSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk size must be greater than 0.');

        User::query()->chunk(0, static fn (array $users): null => null);
    }

    public function testModelQueryRejectsMissingAndInvalidRelationsDuringEagerLoading(): void
    {
        User::create(['email' => 'relations-missing@example.com', 'status' => 'active']);

        try {
            User::query()->with('missingRelation')->get();
            self::fail('Expected missing relation exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                sprintf('Relation "missingRelation" is not defined on model %s.', User::class),
                $exception->getMessage(),
            );
        }

        BrokenRelationUser::create(['email' => 'broken-relation@example.com', 'status' => 'active']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Relation "invalidRelation" on model %s must return %s.',
            BrokenRelationUser::class,
            \Myxa\Database\Model\Relation::class,
        ));

        BrokenRelationUser::query()->with('invalidRelation')->get();
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
            . 'meta TEXT NULL, '
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
            'CREATE TABLE comments ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'post_id INTEGER NOT NULL, '
            . 'body TEXT NOT NULL, '
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
