<?php

declare(strict_types=1);

namespace Test\Unit\Validation;

use BadMethodCallException;
use InvalidArgumentException;
use Myxa\Application;
use Myxa\Database\Connection\PdoConnection;
use Myxa\Database\Connection\PdoConnectionConfig;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Model\Model;
use Myxa\Support\Facades\Validator as ValidatorFacade;
use Myxa\Validation\Exceptions\ValidationException;
use Myxa\Validation\ValidationManager;
use Myxa\Validation\ValidationServiceProvider;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ValidationUser extends Model
{
    protected string $table = 'users';

    protected string $primaryKey = 'id';

    protected string $email = '';
}

#[CoversClass(ValidationManager::class)]
#[CoversClass(\Myxa\Validation\Validator::class)]
#[CoversClass(\Myxa\Validation\FieldValidator::class)]
#[CoversClass(ValidationException::class)]
#[CoversClass(ValidationServiceProvider::class)]
#[CoversClass(ValidatorFacade::class)]
final class ValidationTest extends TestCase
{
    private const string CONNECTION_ALIAS = 'validation-test';

    protected function setUp(): void
    {
        PdoConnection::unregister(self::CONNECTION_ALIAS, false);
        PdoConnection::register(self::CONNECTION_ALIAS, $this->makeConnection(), true);
        ValidationUser::setManager(new DatabaseManager(self::CONNECTION_ALIAS));
    }

    protected function tearDown(): void
    {
        ValidatorFacade::clearManager();
        ValidationUser::clearManager();
        PdoConnection::unregister(self::CONNECTION_ALIAS);
    }

    public function testFluentValidatorPassesAndReturnsValidatedData(): void
    {
        $validator = (new ValidationManager())->make([
            'name' => 'John',
            'email' => 'john@example.com',
            'user_id' => 1,
            'notes' => null,
        ]);

        $validator->field('name')->required()->string()->min(2)->max(50);
        $validator->field('email')->required()->string()->email()->max(255);
        $validator->field('user_id')->required()->integer()->exists(ValidationUser::class);
        $validator->field('notes')->nullable()->string();

        self::assertTrue($validator->passes());
        self::assertSame([
            'name' => 'John',
            'email' => 'john@example.com',
            'user_id' => 1,
            'notes' => null,
        ], $validator->validated());
    }

    public function testFluentValidatorCollectsErrorsAndThrowsException(): void
    {
        $validator = (new ValidationManager())->make([
            'name' => '',
            'email' => 'invalid-email',
            'user_id' => 999,
        ]);

        $validator->field('name')->required()->string()->min(2);
        $validator->field('email')->required()->string()->email();
        $validator->field('user_id')->required()->integer()->exists(ValidationUser::class);
        $validator->field('tags')->required()->array();

        self::assertTrue($validator->fails());
        self::assertSame([
            'name' => [
                'The name field is required.',
            ],
            'email' => [
                'The email field must be a valid email address.',
            ],
            'user_id' => [
                'The selected user_id is invalid.',
            ],
            'tags' => [
                'The tags field is required.',
            ],
        ], $validator->errors());

        try {
            $validator->validate();
            self::fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            self::assertSame('Validation failed.', $exception->getMessage());
            self::assertSame($validator->errors(), $exception->errors());
        }
    }

    public function testExistsSupportsCustomSources(): void
    {
        $validator = (new ValidationManager())->make([
            'role' => 'admin',
            'code' => 'B',
            'email' => 'john@example.com',
        ]);

        $validator->field('role')->exists(['admin', 'editor']);
        $validator->field('code')->exists(static fn (mixed $value): bool => in_array($value, ['A', 'B'], true));
        $validator->field('email')->exists(ValidationUser::class, 'email');

        self::assertTrue($validator->passes());
    }

    public function testNestedFieldsAndWildcardItemsCanBeValidated(): void
    {
        $validator = (new ValidationManager())->make([
            'user' => [
                'name' => 'John',
                'roles' => ['admin', 'editor'],
            ],
        ]);

        $validator->field('user.name')->required()->string()->min(2);
        $validator->field('user.roles')->required()->array()->min(1);
        $validator->field('user.roles.*')->required()->string()->min(3);

        self::assertTrue($validator->passes());
        self::assertSame([
            'user' => [
                'name' => 'John',
                'roles' => ['admin', 'editor'],
            ],
        ], $validator->validated());
    }

    public function testWildcardValidationReportsConcreteNestedPaths(): void
    {
        $validator = (new ValidationManager())->make([
            'user' => [
                'roles' => ['admin', 7, ''],
            ],
        ]);

        $validator->field('user.roles')->required()->array()->min(1);
        $validator->field('user.roles.*')->required()->string();

        self::assertTrue($validator->fails());
        self::assertSame([
            'user.roles.1' => [
                'The user.roles.1 field must be a string.',
            ],
            'user.roles.2' => [
                'The user.roles.2 field is required.',
            ],
        ], $validator->errors());
    }

    public function testNestedRequiredFieldUsesFullPathInErrors(): void
    {
        $validator = (new ValidationManager())->make([
            'user' => [],
        ]);

        $validator->field('user.profile.name')->required()->string();

        self::assertTrue($validator->fails());
        self::assertSame([
            'user.profile.name' => [
                'The user.profile.name field is required.',
            ],
        ], $validator->errors());
    }

    public function testRulesSupportCustomMessagesAndCallables(): void
    {
        $validator = (new ValidationManager())->make([
            'name' => '',
            'email' => 'bad-email',
            'user_id' => 999,
        ]);

        $validator->field('name')->required('Name is mandatory.');
        $validator->field('email')->email(static fn (mixed $value, string $field): string => sprintf(
            '%s "%s" is invalid.',
            $field,
            (string) $value,
        ));
        $validator->field('user_id')->exists(
            ValidationUser::class,
            message: static fn (mixed $value, string $field): string => sprintf(
                '%s "%s" was not found.',
                $field,
                (string) $value,
            ),
        );

        self::assertFalse($validator->passes());
        self::assertSame([
            'name' => [
                'Name is mandatory.',
            ],
            'email' => [
                'email "bad-email" is invalid.',
            ],
            'user_id' => [
                'user_id "999" was not found.',
            ],
        ], $validator->errors());
    }

    public function testExistsRejectsUnsupportedSources(): void
    {
        $validator = (new ValidationManager())->make(['user_id' => 1]);
        $validator->field('user_id')->exists('Unknown\\Model');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Validation source [Unknown\\Model] is not supported.');

        $validator->passes();
    }

    public function testServiceProviderAndFacadeBootstrapValidationManager(): void
    {
        $app = new Application();
        $app->register(new ValidationServiceProvider());
        $app->boot();

        $validator = ValidatorFacade::make([
            'name' => 'John',
        ]);

        $validator->field('name')->required()->string();

        self::assertSame($app->make(ValidationManager::class), ValidatorFacade::getManager());
        self::assertSame(['name' => 'John'], $validator->validate());
    }

    public function testFacadeThrowsClearExceptionForUnknownMethod(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Validator facade method "foobar" is not supported.');

        ValidatorFacade::foobar();
    }

    private function makeConnection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE users ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'email TEXT NOT NULL UNIQUE'
            . ')',
        );
        $pdo->exec("INSERT INTO users (id, email) VALUES (1, 'john@example.com')");

        $connection = new PdoConnection(
            new PdoConnectionConfig(
                engine: 'mysql',
                database: 'placeholder',
                host: '127.0.0.1',
            ),
        );

        $property = new ReflectionProperty(PdoConnection::class, 'pdo');
        $property->setValue($connection, $pdo);

        return $connection;
    }
}
