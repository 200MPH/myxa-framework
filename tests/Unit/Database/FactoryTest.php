<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use BadMethodCallException;
use Myxa\Database\Connection\PdoConnection;
use Myxa\Database\Connection\PdoConnectionConfig;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Factory\Factory;
use Myxa\Database\Factory\FakeData;
use Myxa\Database\Factory\UniqueFakeData;
use Myxa\Database\Model\HasFactory;
use Myxa\Database\Model\HasTimestamps;
use Myxa\Database\Model\Model;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class FactoryUser extends Model
{
    use HasFactory;
    use HasTimestamps;

    protected string $table = 'users';

    protected string $email = '';

    protected string $status = '';

    protected string $title = '';

    protected static function newFactory(): Factory
    {
        return FactoryUserFactory::new();
    }
}

final class FactoryUserFactory extends Factory
{
    protected function model(): string
    {
        return FactoryUser::class;
    }

    protected function definition(): array
    {
        return [
            'email' => $this->faker()->unique('factory-user-email')->email(),
            'status' => $this->faker()->choice(['draft', 'active', 'archived']),
            'title' => $this->faker()->sentence(3, 5),
        ];
    }
}

#[CoversClass(FakeData::class)]
#[CoversClass(UniqueFakeData::class)]
#[CoversClass(Factory::class)]
#[CoversClass(FactoryUser::class)]
final class FactoryTest extends TestCase
{
    private const string CONNECTION_ALIAS = 'factory-test';

    protected function setUp(): void
    {
        PdoConnection::register(self::CONNECTION_ALIAS, $this->makeInMemoryConnection(), true);
        Model::setManager($this->makeManager());
    }

    protected function tearDown(): void
    {
        Model::clearManager();
        PdoConnection::unregister(self::CONNECTION_ALIAS);
    }

    public function testFakeDataGeneratesCommonPrimitiveValues(): void
    {
        $faker = new FakeData();

        $string = $faker->string(20);
        $alpha = $faker->alpha(12);
        $digits = $faker->digits(8);
        $number = $faker->number(10, 20);
        $decimal = $faker->decimal(1, 5, 2);
        $sentence = $faker->sentence(4, 4);
        $slug = $faker->slug(3);
        $email = $faker->email();

        self::assertSame(20, strlen($string));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $string);
        self::assertSame(12, strlen($alpha));
        self::assertMatchesRegularExpression('/^[A-Za-z]+$/', $alpha);
        self::assertSame(8, strlen($digits));
        self::assertMatchesRegularExpression('/^[0-9]+$/', $digits);
        self::assertGreaterThanOrEqual(10, $number);
        self::assertLessThanOrEqual(20, $number);
        self::assertGreaterThanOrEqual(1.0, $decimal);
        self::assertLessThanOrEqual(5.0, $decimal);
        self::assertStringEndsWith('.', $sentence);
        self::assertCount(4, explode(' ', rtrim($sentence, '.')));
        self::assertMatchesRegularExpression('/^[a-z]+(?:-[a-z]+){2}$/', $slug);
        self::assertMatchesRegularExpression('/^[a-z0-9.]+@example\.test$/', $email);
        self::assertContains($faker->choice(['draft', 'active']), ['draft', 'active']);
    }

    public function testFakeDataSupportsUniqueValuesAndScopeReset(): void
    {
        $faker = new FakeData();

        $first = $faker->unique('emails')->email();
        $second = $faker->unique('emails')->email();

        self::assertNotSame($first, $second);

        $faker->unique('fixed-value', 2)->value(static fn (): string => 'same');

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Unable to generate a unique fake value for scope "fixed-value" after 2 attempts.');

        try {
            $faker->unique('fixed-value', 2)->value(static fn (): string => 'same');
        } finally {
            $faker->resetUnique('fixed-value');
            self::assertSame('same', $faker->unique('fixed-value', 2)->value(static fn (): string => 'same'));
        }
    }

    public function testFactoryCanMakeModelsWithoutPersistingThem(): void
    {
        $user = FactoryUser::factory()->make();

        self::assertInstanceOf(FactoryUser::class, $user);
        self::assertFalse($user->exists());
        self::assertMatchesRegularExpression('/@example\.test$/', $user->email);
        self::assertContains($user->status, ['draft', 'active', 'archived']);
        self::assertStringEndsWith('.', $user->title);
    }

    public function testFactoryCanPersistMultipleModels(): void
    {
        $users = FactoryUser::factory()->count(3)->create();

        self::assertCount(3, $users);
        self::assertContainsOnlyInstancesOf(FactoryUser::class, $users);
        self::assertSame(3, (int) $this->makeManager()->select('SELECT COUNT(*) AS total FROM users')[0]['total']);
        self::assertNotSame($users[0]->email, $users[1]->email);
        self::assertNotSame($users[1]->email, $users[2]->email);
    }

    public function testFactorySupportsStatesOverridesAndRawAttributes(): void
    {
        $factory = FactoryUser::factory()
            ->state(['status' => 'archived'])
            ->state(fn (array $attributes, FakeData $faker): array => [
                'title' => strtoupper($faker->sentence(2, 2)),
                'email' => sprintf('state-%s@example.test', $faker->digits(3)),
            ]);

        $raw = $factory->raw(['email' => 'override@example.test']);
        $user = $factory->make(['email' => 'override@example.test']);

        self::assertSame('archived', $raw['status']);
        self::assertSame('override@example.test', $raw['email']);
        self::assertSame('archived', $user->status);
        self::assertSame('override@example.test', $user->email);
        self::assertSame(strtoupper($user->title), $user->title);
    }

    public function testFactoryCanUseAnExplicitManager(): void
    {
        $manager = new DatabaseManager('factory-explicit');
        $manager->addConnection('factory-explicit', $this->makeInMemoryConnection());

        $user = FactoryUser::factory($manager)->create(['status' => 'active']);

        self::assertTrue($user->exists());
        self::assertSame(
            1,
            (int) $manager->select('SELECT COUNT(*) AS total FROM users WHERE status = ?', ['active'])[0]['total'],
        );
        self::assertSame(
            0,
            (int) $this->makeManager()->select('SELECT COUNT(*) AS total FROM users WHERE status = ?', ['active'])[0]['total'],
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
            . 'title TEXT NOT NULL, '
            . 'created_at TEXT NULL, '
            . 'updated_at TEXT NULL'
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
