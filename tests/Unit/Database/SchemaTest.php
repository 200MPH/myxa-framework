<?php

declare(strict_types=1);

namespace Test\Unit\Database;

use LogicException;
use Myxa\Database\Connection\PdoConnection;
use Myxa\Database\Connection\PdoConnectionConfig;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Migrations\Migration;
use Myxa\Database\Query\RawExpression;
use Myxa\Database\Schema\Blueprint;
use Myxa\Database\Schema\ColumnDefinition;
use Myxa\Database\Schema\Diff\ColumnChange;
use Myxa\Database\Schema\Diff\TableDiff;
use Myxa\Database\Schema\Diff\TableDiffer;
use Myxa\Database\Schema\ForeignKeyDefinition;
use Myxa\Database\Schema\Grammar\AbstractSchemaGrammar;
use Myxa\Database\Schema\Grammar\MysqlSchemaGrammar;
use Myxa\Database\Schema\Grammar\PostgresSchemaGrammar;
use Myxa\Database\Schema\Grammar\SqlServerSchemaGrammar;
use Myxa\Database\Schema\Grammar\SqliteSchemaGrammar;
use Myxa\Database\Schema\IndexDefinition;
use Myxa\Database\Schema\ReverseEngineering\ColumnSchema;
use Myxa\Database\Schema\ReverseEngineering\ForeignKeySchema as ReverseForeignKeySchema;
use Myxa\Database\Schema\ReverseEngineering\IndexSchema as ReverseIndexSchema;
use Myxa\Database\Schema\ReverseEngineering\BlueprintTableSchemaFactory;
use Myxa\Database\Schema\ReverseEngineering\Inspector\MysqlSchemaInspector;
use Myxa\Database\Schema\ReverseEngineering\Inspector\PostgresSchemaInspector;
use Myxa\Database\Schema\ReverseEngineering\ModelGenerator;
use Myxa\Database\Schema\ReverseEngineering\ReverseEngineer;
use Myxa\Database\Schema\ReverseEngineering\SchemaSnapshot;
use Myxa\Database\Schema\ReverseEngineering\Inspector\SchemaInspectorInterface;
use Myxa\Database\Schema\ReverseEngineering\Inspector\SqliteSchemaInspector;
use Myxa\Database\Schema\ReverseEngineering\TableSchema;
use Myxa\Database\Schema\ReverseEngineering\Inspector\AbstractSchemaInspector;
use Myxa\Database\Schema\Schema;
use Myxa\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use InvalidArgumentException;

final class ExposedSchemaInspector extends AbstractSchemaInspector
{
    public function table(string $table): TableSchema
    {
        throw new LogicException('not used');
    }

    public function tables(): array
    {
        return [];
    }

    public function normalizeType(
        string $databaseType,
        bool $autoIncrement = false,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
    ): array {
        return $this->normalizeColumnType($databaseType, $autoIncrement, $length, $precision, $scale);
    }

    public function normalizeDefault(mixed $value): array
    {
        return $this->normalizeDefaultValue($value);
    }

    public function buildColumn(
        string $name,
        string $databaseType,
        bool $nullable,
        mixed $defaultValue,
        bool $unsigned = false,
        bool $autoIncrement = false,
        bool $primary = false,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
    ): ColumnSchema {
        return $this->makeColumn(
            $name,
            $databaseType,
            $nullable,
            $defaultValue,
            $unsigned,
            $autoIncrement,
            $primary,
            $length,
            $precision,
            $scale,
        );
    }
}

#[CoversClass(Blueprint::class)]
#[CoversClass(ColumnDefinition::class)]
#[CoversClass(ForeignKeyDefinition::class)]
#[CoversClass(Migration::class)]
#[CoversClass(RawExpression::class)]
#[CoversClass(IndexDefinition::class)]
#[CoversClass(ColumnChange::class)]
#[CoversClass(TableDiff::class)]
#[CoversClass(TableDiffer::class)]
#[CoversClass(AbstractSchemaGrammar::class)]
#[CoversClass(MysqlSchemaGrammar::class)]
#[CoversClass(PostgresSchemaGrammar::class)]
#[CoversClass(SqlServerSchemaGrammar::class)]
#[CoversClass(SqliteSchemaGrammar::class)]
#[CoversClass(ColumnSchema::class)]
#[CoversClass(ReverseForeignKeySchema::class)]
#[CoversClass(ReverseIndexSchema::class)]
#[CoversClass(BlueprintTableSchemaFactory::class)]
#[CoversClass(MysqlSchemaInspector::class)]
#[CoversClass(PostgresSchemaInspector::class)]
#[CoversClass(ModelGenerator::class)]
#[CoversClass(SqliteSchemaInspector::class)]
#[CoversClass(ReverseEngineer::class)]
#[CoversClass(SchemaSnapshot::class)]
#[CoversClass(TableSchema::class)]
#[CoversClass(Schema::class)]
#[CoversClass(DB::class)]
#[CoversClass(DatabaseManager::class)]
#[CoversClass(PdoConnection::class)]
#[CoversClass(PdoConnectionConfig::class)]
final class SchemaTest extends TestCase
{
    private const string CONNECTION_ALIAS = 'schema-test';

    protected function setUp(): void
    {
        PdoConnection::unregister(self::CONNECTION_ALIAS, false);
        PdoConnection::register(self::CONNECTION_ALIAS, $this->makeInMemoryConnection(), true);
    }

    protected function tearDown(): void
    {
        DB::clearManager();
        PdoConnection::unregister(self::CONNECTION_ALIAS);
    }

    public function testManagerAndFacadeExposeSchemaBuilder(): void
    {
        $manager = $this->makeManager();
        DB::setManager($manager);

        self::assertInstanceOf(Schema::class, $manager->schema());
        self::assertInstanceOf(Schema::class, DB::schema(self::CONNECTION_ALIAS));

        DB::schema(self::CONNECTION_ALIAS)->create('notes', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        self::assertSame(['id', 'title'], array_column($this->tableInfo('notes'), 'name'));
    }

    public function testBlueprintTableSchemaFactoryBuildsNormalizedTableSchema(): void
    {
        $blueprint = Blueprint::create('audit_logs');
        $blueprint->id();
        $blueprint->string('event')->unique();
        $blueprint->bigInteger('user_id')->unsigned();
        $blueprint->foreign('user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('cascade');
        $blueprint->index('created_at');

        $schema = (new BlueprintTableSchemaFactory())->fromBlueprint($blueprint);

        self::assertSame('audit_logs', $schema->name());
        self::assertSame(['id', 'event', 'user_id'], array_map(
            static fn (ColumnSchema $column): string => $column->name(),
            $schema->columns(),
        ));
        self::assertSame(
            [ReverseIndexSchema::TYPE_UNIQUE, ReverseIndexSchema::TYPE_INDEX],
            array_map(static fn (ReverseIndexSchema $index): string => $index->type(), $schema->indexes()),
        );
        self::assertSame('users', $schema->foreignKeys()[0]->referencedTable());
        self::assertSame(['user_id'], $schema->foreignKeys()[0]->columns());
    }

    public function testSchemaCreatesTableWithIndexesAndForeignKeys(): void
    {
        $schema = $this->makeManager()->schema();

        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unsigned();
            $table->string('title')->unique();
            $table->text('body')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        $columns = $this->tableInfo('posts');
        $indexes = $this->indexList('posts');
        $foreignKeys = $this->foreignKeyList('posts');

        self::assertSame(
            ['id', 'user_id', 'title', 'body', 'published', 'created_at', 'updated_at'],
            array_column($columns, 'name'),
        );
        self::assertSame('INTEGER', strtoupper((string) $columns[0]['type']));
        self::assertSame('0', (string) $columns[4]['dflt_value']);
        self::assertContains('posts_title_unique', array_column($indexes, 'name'));
        self::assertSame('users', $foreignKeys[0]['table']);
        self::assertSame('user_id', $foreignKeys[0]['from']);
        self::assertSame('id', $foreignKeys[0]['to']);
        self::assertSame('CASCADE', strtoupper((string) $foreignKeys[0]['on_delete']));
    }

    public function testSchemaAltersExistingTablesByAddingAndRenamingColumns(): void
    {
        $schema = $this->makeManager()->schema();

        $schema->table('users', function (Blueprint $table): void {
            $table->string('nickname', 120)->nullable()->index();
        });

        $columnsAfterAdd = $this->tableInfo('users');
        $indexesAfterAdd = $this->indexList('users');

        self::assertContains('nickname', array_column($columnsAfterAdd, 'name'));
        self::assertContains('users_nickname_index', array_column($indexesAfterAdd, 'name'));

        $schema->table('users', function (Blueprint $table): void {
            $table->renameColumn('status', 'account_status');
        });

        $columnsAfterRename = $this->tableInfo('users');

        self::assertContains('account_status', array_column($columnsAfterRename, 'name'));
        self::assertNotContains('status', array_column($columnsAfterRename, 'name'));
    }

    public function testMysqlGrammarCompilesCreateAndAlterStatements(): void
    {
        $create = Blueprint::create('posts');
        $create->id();
        $create->bigInteger('user_id')->unsigned();
        $create->string('title')->unique();
        $create->text('body')->nullable();
        $create->timestamp('published_at')->nullable()->useCurrent();
        $create->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

        $alter = Blueprint::table('posts');
        $alter->string('slug', 120)->nullable()->unique();
        $alter->renameColumn('title', 'headline');
        $alter->dropColumn('legacy_code');
        $alter->raw('ANALYZE TABLE `posts`');

        $grammar = new MysqlSchemaGrammar();

        self::assertSame(
            [
                'CREATE TABLE `posts` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `user_id` BIGINT UNSIGNED NOT NULL, `title` VARCHAR(255) NOT NULL, `body` TEXT NULL, `published_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT `posts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE)',
                'CREATE UNIQUE INDEX `posts_title_unique` ON `posts` (`title`)',
            ],
            $grammar->compileCreate($create),
        );

        self::assertSame(
            [
                'ALTER TABLE `posts` ADD COLUMN `slug` VARCHAR(120) NULL',
                'ALTER TABLE `posts` RENAME COLUMN `title` TO `headline`',
                'ALTER TABLE `posts` DROP COLUMN `legacy_code`',
                'CREATE UNIQUE INDEX `posts_slug_unique` ON `posts` (`slug`)',
                'ANALYZE TABLE `posts`',
            ],
            $grammar->compileAlter($alter),
        );
    }

    public function testMigrationResolvesConnectionScopedSchema(): void
    {
        $manager = $this->makeManager();

        $migration = new class extends Migration
        {
            public function connectionName(): ?string
            {
                return 'schema-test';
            }

            public function up(Schema $schema): void
            {
                $schema->create('audit_logs', function (Blueprint $table): void {
                    $table->id();
                    $table->string('message');
                });
            }
        };

        self::assertTrue($migration->withinTransaction());

        $migration->up($migration->schema($manager));

        self::assertSame(['id', 'message'], array_column($this->tableInfo('audit_logs'), 'name'));
    }

    public function testMigrationDefaultsAreSensible(): void
    {
        $migration = new class extends Migration
        {
            public function up(Schema $schema): void
            {
            }
        };

        self::assertNull($migration->connectionName());
        self::assertTrue($migration->withinTransaction());
        self::assertNull($migration->schema($this->makeManager())->connectionName());
    }

    public function testPostgresGrammarCompilesCreateAndAlterStatements(): void
    {
        $create = Blueprint::create('posts');
        $create->id();
        $create->bigInteger('user_id');
        $create->string('title')->unique();
        $create->json('meta')->nullable();
        $create->timestamp('published_at')->nullable()->useCurrent();
        $create->foreign('user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('restrict');

        $alter = Blueprint::table('posts');
        $alter->string('slug', 120)->nullable()->unique();
        $alter->renameColumn('title', 'headline');
        $alter->dropColumn('legacy_code');
        $alter->raw('VACUUM ANALYZE "posts"');

        $grammar = new PostgresSchemaGrammar();

        self::assertSame(
            [
                'CREATE TABLE "posts" ("id" BIGSERIAL NOT NULL PRIMARY KEY, "user_id" BIGINT NOT NULL, "title" VARCHAR(255) NOT NULL, "meta" JSONB NULL, "published_at" TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT "posts_user_id_foreign" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE ON UPDATE RESTRICT)',
                'CREATE UNIQUE INDEX "posts_title_unique" ON "posts" ("title")',
            ],
            $grammar->compileCreate($create),
        );

        self::assertSame(
            [
                'ALTER TABLE "posts" ADD COLUMN "slug" VARCHAR(120) NULL',
                'ALTER TABLE "posts" RENAME COLUMN "title" TO "headline"',
                'ALTER TABLE "posts" DROP COLUMN "legacy_code"',
                'CREATE UNIQUE INDEX "posts_slug_unique" ON "posts" ("slug")',
                'VACUUM ANALYZE "posts"',
            ],
            $grammar->compileAlter($alter),
        );
    }

    public function testMigrationDownThrowsWhenNotImplemented(): void
    {
        $migration = new class extends Migration
        {
            public function up(Schema $schema): void
            {
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf(
            'Migration %s does not implement down().',
            $migration::class,
        ));

        $migration->down($this->makeManager()->schema());
    }

    public function testSchemaStatementExecutesSqlWithBindings(): void
    {
        $schema = $this->makeManager()->schema();

        self::assertTrue($schema->statement(
            'UPDATE users SET status = ? WHERE email = ?',
            ['pending', 'john@example.com'],
        ));

        $rows = $this->pdo()->query(
            "SELECT status FROM users WHERE email = 'john@example.com'",
        );

        self::assertNotFalse($rows);
        self::assertSame('pending', $rows->fetch(PDO::FETCH_ASSOC)['status']);
    }

    public function testSchemaCanReverseEngineerSqliteTableDefinitions(): void
    {
        $schema = $this->makeManager()->schema();

        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unsigned();
            $table->string('title', 120)->unique();
            $table->boolean('published')->default(false);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        $definition = $schema->reverseEngineer()->table('posts');

        self::assertSame('posts', $definition->name());
        self::assertSame(['posts', 'users'], $schema->reverseEngineer()->tables());
        self::assertSame(
            ['id', 'user_id', 'title', 'published'],
            array_map(static fn (ColumnSchema $column): string => $column->name(), $definition->columns()),
        );
        self::assertSame('bigInteger', $definition->columns()[0]->type());
        self::assertTrue($definition->columns()[0]->isAutoIncrement());
        self::assertTrue($definition->columns()[0]->isPrimary());
        self::assertSame('string', $definition->columns()[2]->type());
        self::assertSame(120, $definition->columns()[2]->option('length'));
        self::assertTrue($definition->columns()[3]->hasDefault());
        self::assertSame(0, $definition->columns()[3]->defaultValue());
        self::assertContainsOnlyInstancesOf(ReverseIndexSchema::class, $definition->indexes());
        self::assertContainsOnlyInstancesOf(ReverseForeignKeySchema::class, $definition->foreignKeys());
        self::assertSame('users', $definition->foreignKeys()[0]->referencedTable());
    }

    public function testSchemaCanGenerateMigrationSourceFromSqliteTable(): void
    {
        $schema = $this->makeManager()->schema();

        $schema->create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 100);
            $table->boolean('processed')->default(false);
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->unique('event', 'audit_logs_event_unique');
        });

        $source = $schema->reverseEngineer()->migration('audit_logs', 'CreateAuditLogsTable');

        self::assertStringContainsString('final class CreateAuditLogsTable extends Migration', $source);
        self::assertStringContainsString("\$schema->create('audit_logs'", $source);
        self::assertStringContainsString("\$table->id('id');", $source);
        self::assertStringContainsString("\$table->string('event', 100);", $source);
        self::assertStringContainsString("\$table->integer('processed')->default(0);", $source);
        self::assertStringContainsString("\$table->dateTime('created_at')->nullable()->useCurrent();", $source);
        self::assertStringContainsString("\$table->unique('event', 'audit_logs_event_unique');", $source);
    }

    public function testReverseEngineerCanDiffAndGenerateAlterMigration(): void
    {
        $schema = $this->makeManager()->schema();

        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120)->unique();
            $table->text('body')->nullable();
        });

        $before = $schema->reverseEngineer()->table('posts');

        $schema->statement('DROP INDEX "posts_title_unique"');
        $schema->statement('ALTER TABLE "posts" DROP COLUMN "body"');
        $schema->table('posts', function (Blueprint $table): void {
            $table->string('slug', 120)->nullable()->unique();
        });

        $after = $schema->reverseEngineer()->table('posts');
        $diff = $schema->reverseEngineer()->diff($before, $after);
        $source = $schema->reverseEngineer()->alterMigration($before, $after, 'AlterPostsTable');

        self::assertTrue($diff->hasChanges());
        self::assertFalse($diff->hasChangedColumns());
        self::assertSame(['slug'], array_map(static fn (ColumnSchema $column): string => $column->name(), $diff->addedColumns()));
        self::assertSame(['body'], array_map(static fn (ColumnSchema $column): string => $column->name(), $diff->droppedColumns()));
        self::assertSame(['posts_slug_unique'], array_map(static fn (ReverseIndexSchema $index): string => $index->name(), $diff->addedIndexes()));
        self::assertSame(['posts_title_unique'], array_map(static fn (ReverseIndexSchema $index): string => $index->name(), $diff->droppedIndexes()));
        self::assertStringContainsString("final class AlterPostsTable extends Migration", $source);
        self::assertStringContainsString("\$table->dropIndex('posts_title_unique');", $source);
        self::assertStringContainsString("\$table->dropColumn('body');", $source);
        self::assertStringContainsString("\$table->string('slug', 120)->nullable();", $source);
        self::assertStringContainsString("\$table->unique('slug', 'posts_slug_unique');", $source);
    }

    public function testAlterMigrationGenerationRejectsChangedColumns(): void
    {
        $reverseEngineer = $this->makeManager()->schema()->reverseEngineer();
        $before = new TableSchema('users', [
            new ColumnSchema('name', 'string', false, null, false, false, false, false, ['length' => 100]),
        ], [], []);
        $after = new TableSchema('users', [
            new ColumnSchema('name', 'string', false, null, false, false, false, false, ['length' => 200]),
        ], [], []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Alter migration generation does not yet support modified columns: name.');

        $reverseEngineer->alterMigration($before, $after, 'AlterUsersTable');
    }

    public function testReverseEngineerCanRoundTripSchemaSnapshotJson(): void
    {
        $schema = $this->makeManager()->schema();

        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120)->unique();
            $table->text('body')->nullable();
        });

        $snapshot = $schema->reverseEngineer()->snapshot();
        $json = $snapshot->toJson();
        $loaded = $schema->reverseEngineer()->snapshotFromJson($json);

        self::assertSame(['posts', 'users'], $snapshot->tableNames());
        self::assertSame($snapshot->tableNames(), $loaded->tableNames());
        self::assertSame('posts', $loaded->table('posts')->name());
        self::assertSame('string', $loaded->table('posts')->columns()[1]->type());
        self::assertSame(120, $loaded->table('posts')->columns()[1]->option('length'));
        self::assertStringContainsString('"tables"', $json);
        self::assertStringContainsString('"posts"', $json);
    }

    public function testReverseEngineerCanDiffSnapshotAgainstLiveSchema(): void
    {
        $schema = $this->makeManager()->schema();

        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120)->unique();
        });

        $snapshot = $schema->reverseEngineer()->snapshot();

        $schema->statement('DROP INDEX "posts_title_unique"');
        $schema->table('posts', function (Blueprint $table): void {
            $table->string('slug', 120)->nullable()->unique();
        });

        $live = $schema->reverseEngineer()->table('posts');
        $diff = $schema->reverseEngineer()->diff($snapshot->table('posts'), $live);
        $source = $schema->reverseEngineer()->alterMigration($snapshot->table('posts'), $live, 'AlterPostsTable');

        self::assertTrue($diff->hasChanges());
        self::assertSame('slug', $diff->addedColumns()[0]->name());
        self::assertSame('posts_title_unique', $diff->droppedIndexes()[0]->name());
        self::assertStringContainsString("\$table->dropIndex('posts_title_unique');", $source);
        self::assertStringContainsString("\$table->string('slug', 120)->nullable();", $source);
    }

    public function testBlueprintExposesAuxiliaryOperationsAndCollectedState(): void
    {
        $blueprint = Blueprint::table('users');
        $blueprint->integer('age');
        $blueprint->text('bio');
        $blueprint->boolean('active');
        $blueprint->timestamp('created_at');
        $blueprint->dateTime('seen_at');
        $blueprint->json('meta');
        $blueprint->decimal('score', 10, 4);
        $blueprint->float('ratio');
        $blueprint->primary(['age'], 'users_age_primary');
        $blueprint->index(['age', 'active'], 'users_age_active_index');
        $blueprint->foreign('age', 'users_age_foreign')->references('id')->on('profiles')->onDelete('cascade')->onUpdate('restrict');
        $blueprint->dropColumn(['legacy_a', 'legacy_b']);
        $blueprint->renameColumn('old_name', 'new_name');
        $blueprint->dropIndex('users_old_index');
        $blueprint->dropForeign('users_old_foreign');
        $blueprint->raw('VACUUM');

        self::assertFalse($blueprint->isCreate());
        self::assertSame('users', $blueprint->tableName());
        self::assertCount(8, $blueprint->columns());
        self::assertCount(2, $blueprint->indexes());
        self::assertCount(1, $blueprint->foreignKeys());
        self::assertSame(['legacy_a', 'legacy_b'], $blueprint->droppedColumns());
        self::assertSame([['from' => 'old_name', 'to' => 'new_name']], $blueprint->renamedColumns());
        self::assertSame(['users_old_index'], $blueprint->droppedIndexes());
        self::assertSame(['users_old_foreign'], $blueprint->droppedForeignKeys());
        self::assertSame(['VACUUM'], $blueprint->rawStatements());
    }

    public function testBlueprintValidationErrorsAreHelpful(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Table name cannot be empty.');

        Blueprint::create(' ');
    }

    public function testBlueprintRejectsInvalidLengthsNamesAndStatements(): void
    {
        $blueprint = Blueprint::table('users');

        try {
            $blueprint->string('name', 0);
            self::fail('Expected exception for invalid string length.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('String column length must be greater than 0.', $exception->getMessage());
        }

        try {
            $blueprint->decimal('score', 0, 2);
            self::fail('Expected exception for invalid precision.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Decimal precision must be greater than 0.', $exception->getMessage());
        }

        try {
            $blueprint->decimal('score', 3, -1);
            self::fail('Expected exception for invalid scale.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Decimal scale cannot be negative.', $exception->getMessage());
        }

        try {
            $blueprint->decimal('score', 3, 4);
            self::fail('Expected exception for invalid scale > precision.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Decimal scale cannot be greater than precision.', $exception->getMessage());
        }

        try {
            $blueprint->primary([]);
            self::fail('Expected exception for empty index columns.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Index and key columns cannot be empty.', $exception->getMessage());
        }

        try {
            $blueprint->renameColumn(' ', 'new_name');
            self::fail('Expected exception for invalid rename column.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Rename column names cannot be empty.', $exception->getMessage());
        }

        try {
            $blueprint->dropIndex(' ');
            self::fail('Expected exception for empty index name.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Index name cannot be empty.', $exception->getMessage());
        }

        try {
            $blueprint->dropForeign(' ');
            self::fail('Expected exception for empty foreign key name.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Foreign key name cannot be empty.', $exception->getMessage());
        }

        try {
            $blueprint->raw(' ');
            self::fail('Expected exception for empty raw statement.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Raw schema statement cannot be empty.', $exception->getMessage());
        }
    }

    public function testColumnDefinitionAndForeignKeyDefinitionAccessorsAndValidation(): void
    {
        $blueprint = Blueprint::table('users');
        $column = $blueprint->integer('age')
            ->nullable()
            ->default(5)
            ->unsigned()
            ->autoIncrement()
            ->primary()
            ->unique('users_age_unique')
            ->index('users_age_index');

        self::assertSame('age', $column->name());
        self::assertSame('integer', $column->type());
        self::assertFalse($column->isNullable());
        self::assertTrue($column->isUnsigned());
        self::assertTrue($column->isAutoIncrement());
        self::assertTrue($column->isPrimary());
        self::assertTrue($column->hasDefault());
        self::assertSame(5, $column->defaultValue());
        self::assertSame([], $column->options());
        self::assertSame('fallback', $column->option('missing', 'fallback'));

        $foreign = $blueprint->foreign('profile_id', 'users_profile_foreign');
        self::assertSame(['profile_id'], $foreign->columns());
        self::assertSame('users_profile_foreign', $foreign->name());

        try {
            $foreign->table();
            self::fail('Expected missing table exception.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('must declare a target table', $exception->getMessage());
        }

        try {
            $foreign->referencedColumns();
            self::fail('Expected missing references exception.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('must declare referenced columns', $exception->getMessage());
        }

        $foreign->references('id')->on('profiles')->onDelete('set null')->onUpdate('cascade');
        self::assertSame('profiles', $foreign->table());
        self::assertSame(['id'], $foreign->referencedColumns());
        self::assertSame('SET NULL', $foreign->deleteAction());
        self::assertSame('CASCADE', $foreign->updateAction());

        try {
            $column->default(new \stdClass());
            self::fail('Expected invalid default exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Schema default value must be scalar, null, or a raw expression.', $exception->getMessage());
        }

        try {
            $foreign->onDelete(' ');
            self::fail('Expected invalid action exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Foreign key action cannot be empty.', $exception->getMessage());
        }
    }

    public function testIndexDefinitionValidationAndAccessors(): void
    {
        $index = new IndexDefinition(IndexDefinition::TYPE_INDEX, ['email'], 'users_email_index');
        self::assertSame(IndexDefinition::TYPE_INDEX, $index->type());
        self::assertSame(['email'], $index->columns());
        self::assertSame('users_email_index', $index->name());

        try {
            new IndexDefinition('weird', ['email'], 'bad');
            self::fail('Expected unsupported index type exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Unsupported index type "weird".', $exception->getMessage());
        }

        try {
            new IndexDefinition(IndexDefinition::TYPE_INDEX, [], 'bad');
            self::fail('Expected empty index columns exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Index columns cannot be empty.', $exception->getMessage());
        }

        try {
            new IndexDefinition(IndexDefinition::TYPE_INDEX, [' '], 'bad');
            self::fail('Expected empty index column name exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Index column names cannot be empty.', $exception->getMessage());
        }

        try {
            new IndexDefinition(IndexDefinition::TYPE_INDEX, ['email'], ' ');
            self::fail('Expected empty index name exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Index name cannot be empty.', $exception->getMessage());
        }
    }

    public function testSchemaUtilityOperationsAndFacadeExposure(): void
    {
        $manager = $this->makeManager();
        DB::setManager($manager);
        $schema = $manager->schema();
        $other = $schema->connection('other');

        self::assertNull($schema->connectionName());
        self::assertSame('other', $other->connectionName());
        self::assertInstanceOf(ReverseEngineer::class, $schema->reverseEngineer());

        $schema->create('archives', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        self::assertTrue($schema->raw('INSERT INTO archives (label) VALUES (\'first\')'));
        self::assertSame('first', $this->makeManager()->select('SELECT label FROM archives')[0]['label']);

        $schema->rename('archives', 'archive_items');
        self::assertSame(['id', 'label'], array_column($this->tableInfo('archive_items'), 'name'));

        $schema->dropIfExists('archive_items');
        self::assertSame([], $this->tableInfo('archive_items'));
        $schema->dropIfExists('archive_items');

        $schema->create('trash', function (Blueprint $table): void {
            $table->id();
        });
        $schema->drop('trash');
        self::assertSame([], $this->tableInfo('trash'));
    }

    public function testGrammarDropOperationsAndTypeMappings(): void
    {
        $mysql = new MysqlSchemaGrammar();
        $postgres = new PostgresSchemaGrammar();
        $sqlite = new SqliteSchemaGrammar();

        self::assertSame('DROP INDEX `idx_users_email` ON `users`', $mysql->compileDropIndex('users', 'idx_users_email'));
        self::assertSame('ALTER TABLE `users` DROP FOREIGN KEY `users_profile_foreign`', $mysql->compileDropForeign('users', 'users_profile_foreign'));
        self::assertSame('DROP INDEX "idx_users_email"', $postgres->compileDropIndex('users', 'idx_users_email'));
        self::assertSame('ALTER TABLE "users" DROP CONSTRAINT "users_profile_foreign"', $postgres->compileDropForeign('users', 'users_profile_foreign'));
        self::assertSame('DROP INDEX "idx_users_email"', $sqlite->compileDropIndex('users', 'idx_users_email'));

        try {
            $sqlite->compileDropForeign('users', 'users_profile_foreign');
            self::fail('Expected SQLite drop foreign exception.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('cannot drop foreign keys', $exception->getMessage());
        }

        $blueprint = Blueprint::create('metrics');
        $blueprint->integer('count');
        $blueprint->dateTime('seen_at');
        $blueprint->json('meta');
        $blueprint->decimal('score', 9, 3);
        $blueprint->float('ratio');
        $mysqlCreate = $mysql->compileCreate($blueprint)[0];
        $postgresCreate = $postgres->compileCreate($blueprint)[0];
        $sqliteCreate = $sqlite->compileCreate($blueprint)[0];

        self::assertStringContainsString('INT', $mysqlCreate);
        self::assertStringContainsString('DATETIME', $mysqlCreate);
        self::assertStringContainsString('JSON', $mysqlCreate);
        self::assertStringContainsString('DECIMAL(9, 3)', $mysqlCreate);
        self::assertStringContainsString('DOUBLE', $mysqlCreate);
        self::assertStringContainsString('JSONB', $postgresCreate);
        self::assertStringContainsString('DOUBLE PRECISION', $postgresCreate);
        $sqliteStringBlueprint = Blueprint::create('tmp');
        $sqliteStringBlueprint->string('name');
        self::assertStringContainsString('VARCHAR', $sqlite->compileCreate($sqliteStringBlueprint)[0]);
        self::assertStringContainsString('NUMERIC', $sqliteCreate);

        $invalid = Blueprint::create('invalid');
        $invalid->string('name')->autoIncrement();

        try {
            $postgres->compileCreate($invalid);
            self::fail('Expected unsupported PostgreSQL autoincrement type exception.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('Unsupported PostgreSQL auto-increment schema column type "string"', $exception->getMessage());
        }
    }

    public function testGrammarCoversCreateAlterAndSqliteFailureEdges(): void
    {
        $grammar = new MysqlSchemaGrammar();

        self::assertSame('DROP TABLE IF EXISTS `users`', $grammar->compileDrop('users', true));
        self::assertSame('ALTER TABLE `users` RENAME TO `members`', $grammar->compileRename('users', 'members'));

        $empty = Blueprint::create('empty_table');

        try {
            $grammar->compileCreate($empty);
            self::fail('Expected empty create blueprint exception.');
        } catch (LogicException $exception) {
            self::assertSame('Cannot create table "empty_table" without any columns or constraints.', $exception->getMessage());
        }

        $withPrimary = Blueprint::table('users');
        $withPrimary->primary(['id']);

        try {
            (new SqliteSchemaGrammar())->compileAlter($withPrimary);
            self::fail('Expected SQLite add primary key exception.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('cannot add a primary key', $exception->getMessage());
        }

        $withForeignKey = Blueprint::table('users');
        $withForeignKey->foreign('profile_id')->references('id')->on('profiles');

        try {
            (new SqliteSchemaGrammar())->compileAlter($withForeignKey);
            self::fail('Expected SQLite add foreign key exception.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('cannot add foreign keys', $exception->getMessage());
        }
    }

    public function testSqlServerGrammarCompilesCreateAlterAndUtilityStatements(): void
    {
        $create = Blueprint::create('posts');
        $create->id();
        $create->integer('views');
        $create->bigInteger('user_id');
        $create->string('title')->unique();
        $create->json('meta')->nullable();
        $create->timestamp('published_at')->nullable()->useCurrent();
        $create->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

        $alter = Blueprint::table('posts');
        $alter->string('slug', 120)->nullable()->unique();
        $alter->renameColumn('title', 'headline');
        $alter->dropColumn('legacy_code');
        $alter->raw('UPDATE STATISTICS [posts]');

        $grammar = new SqlServerSchemaGrammar();

        self::assertSame(
            [
                'CREATE TABLE [posts] ([id] BIGINT NOT NULL IDENTITY(1,1) PRIMARY KEY, [views] INT NOT NULL, [user_id] BIGINT NOT NULL, [title] NVARCHAR(255) NOT NULL, [meta] NVARCHAR(MAX) NULL, [published_at] DATETIME2 NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT [posts_user_id_foreign] FOREIGN KEY ([user_id]) REFERENCES [users] ([id]) ON DELETE CASCADE)',
                'CREATE UNIQUE INDEX [posts_title_unique] ON [posts] ([title])',
            ],
            $grammar->compileCreate($create),
        );

        self::assertSame(
            [
                'ALTER TABLE [posts] ADD COLUMN [slug] NVARCHAR(120) NULL',
                "EXEC sp_rename N'posts.title', N'headline', N'COLUMN'",
                'ALTER TABLE [posts] DROP COLUMN [legacy_code]',
                'CREATE UNIQUE INDEX [posts_slug_unique] ON [posts] ([slug])',
                'UPDATE STATISTICS [posts]',
            ],
            $grammar->compileAlter($alter),
        );

        self::assertSame(
            "IF OBJECT_ID(N'posts', N'U') IS NOT NULL DROP TABLE [posts]",
            $grammar->compileDrop('posts', true),
        );
        self::assertSame('DROP TABLE [posts]', $grammar->compileDrop('posts'));
        self::assertSame(
            "EXEC sp_rename N'posts', N'archived_posts'",
            $grammar->compileRename('posts', 'archived_posts'),
        );
        self::assertSame(
            'DROP INDEX [posts_title_unique] ON [posts]',
            $grammar->compileDropIndex('posts', 'posts_title_unique'),
        );
        self::assertSame(
            'ALTER TABLE [posts] DROP CONSTRAINT [posts_user_id_foreign]',
            $grammar->compileDropForeign('posts', 'posts_user_id_foreign'),
        );
    }

    public function testSchemaGrammarCoversPrimaryConstraintsRawStatementsAndDefaultValues(): void
    {
        $create = Blueprint::create('settings');
        $create->integer('id');
        $create->string('name')->default("app's name");
        $create->integer('count')->default(5);
        $create->decimal('ratio')->default(1.25);
        $create->boolean('enabled')->default(true);
        $create->boolean('disabled')->default(false);
        $create->string('optional')->nullable()->default(null);
        $create->timestamp('created_at')->default(new RawExpression('CURRENT_TIMESTAMP'));
        $create->primary(['id']);
        $create->raw('ANALYZE settings');

        $sql = (new MysqlSchemaGrammar())->compileCreate($create);

        self::assertStringContainsString('PRIMARY KEY (`id`)', $sql[0]);
        self::assertStringContainsString("DEFAULT 'app''s name'", $sql[0]);
        self::assertStringContainsString('DEFAULT 5', $sql[0]);
        self::assertStringContainsString('DEFAULT 1.25', $sql[0]);
        self::assertStringContainsString('DEFAULT 1', $sql[0]);
        self::assertStringContainsString('DEFAULT 0', $sql[0]);
        self::assertStringContainsString('DEFAULT NULL', $sql[0]);
        self::assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $sql[0]);
        self::assertSame('ANALYZE settings', $sql[1]);
    }

    public function testSchemaInspectorHelpersNormalizeTypesDefaultsAndErrors(): void
    {
        $inspector = new ExposedSchemaInspector($this->makeManager());

        self::assertSame('bigInteger', $inspector->normalizeType('INTEGER', true)['type']);
        self::assertSame('integer', $inspector->normalizeType('int')['type']);
        self::assertSame(120, $inspector->normalizeType('varchar', false, 120)['options']['length']);
        self::assertSame('text', $inspector->normalizeType('text')['type']);
        self::assertSame('json', $inspector->normalizeType('jsonb')['type']);
        self::assertSame('boolean', $inspector->normalizeType('boolean')['type']);
        self::assertSame('timestamp', $inspector->normalizeType('timestamp')['type']);
        self::assertSame('dateTime', $inspector->normalizeType('datetime')['type']);
        self::assertSame(10, $inspector->normalizeType('decimal', false, null, 10, 4)['options']['precision']);
        self::assertSame('float', $inspector->normalizeType('double')['type']);
        self::assertSame('text', $inspector->normalizeType('something-odd')['type']);

        self::assertSame(['value' => null, 'hasDefault' => false], $inspector->normalizeDefault(null));
        self::assertSame(['value' => null, 'hasDefault' => true], $inspector->normalizeDefault('null'));
        self::assertSame(['value' => 'CURRENT_TIMESTAMP', 'hasDefault' => true], $inspector->normalizeDefault("'CURRENT_TIMESTAMP'"));
        self::assertSame(['value' => 12, 'hasDefault' => true], $inspector->normalizeDefault('12'));
        self::assertSame(['value' => 'hello', 'hasDefault' => true], $inspector->normalizeDefault("'hello'"));
        self::assertSame(['value' => true, 'hasDefault' => true], $inspector->normalizeDefault(true));

        $column = $inspector->buildColumn('age', 'int', false, '12', true, true, true);
        self::assertSame('age', $column->name());
        self::assertSame('bigInteger', $column->type());
        self::assertTrue($column->isUnsigned());
        self::assertTrue($column->isAutoIncrement());
        self::assertTrue($column->isPrimary());
        self::assertSame(12, $column->defaultValue());

        try {
            $inspector->normalizeDefault(new \stdClass());
            self::fail('Expected unsupported inspector default exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Unsupported default value returned from schema inspector.', $exception->getMessage());
        }
    }

    public function testMysqlSchemaInspectorParsesInformationSchemaRows(): void
    {
        $manager = new DatabaseManager('mysql-inspector');
        $manager->addConnection('mysql-inspector', $this->makeMysqlInspectorConnection());
        $inspector = new MysqlSchemaInspector($manager, 'mysql-inspector');

        $table = $inspector->table('posts');

        self::assertSame(['posts', 'users'], $inspector->tables());
        self::assertSame('posts', $table->name());
        self::assertSame(['id', 'user_id', 'title', 'score'], array_map(
            static fn (ColumnSchema $column): string => $column->name(),
            $table->columns(),
        ));
        self::assertSame('bigInteger', $table->columns()[0]->type());
        self::assertTrue($table->columns()[0]->isAutoIncrement());
        self::assertTrue($table->columns()[0]->isPrimary());
        self::assertTrue($table->columns()[1]->isUnsigned());
        self::assertSame('decimal', $table->columns()[3]->type());
        self::assertSame(9, $table->columns()[3]->option('precision'));
        self::assertSame(3, $table->columns()[3]->option('scale'));
        self::assertSame(ReverseIndexSchema::TYPE_PRIMARY, $table->indexes()[0]->type());
        self::assertSame(ReverseIndexSchema::TYPE_UNIQUE, $table->indexes()[1]->type());
        self::assertSame('users', $table->foreignKeys()[0]->referencedTable());
        self::assertSame(['user_id'], $table->foreignKeys()[0]->columns());
        self::assertSame(['id'], $table->foreignKeys()[0]->referencedColumns());
        self::assertSame('CASCADE', $table->foreignKeys()[0]->onDelete());
        self::assertSame('RESTRICT', $table->foreignKeys()[0]->onUpdate());
    }

    public function testPostgresSchemaInspectorParsesInformationSchemaRows(): void
    {
        $manager = new DatabaseManager('pgsql-inspector');
        $manager->addConnection('pgsql-inspector', $this->makePostgresInspectorConnection());
        $inspector = new PostgresSchemaInspector($manager, 'pgsql-inspector');

        $table = $inspector->table('posts');

        self::assertSame(['posts', 'users'], $inspector->tables());
        self::assertSame('posts', $table->name());
        self::assertSame(['id', 'user_id', 'title'], array_map(
            static fn (ColumnSchema $column): string => $column->name(),
            $table->columns(),
        ));
        self::assertSame('bigInteger', $table->columns()[0]->type());
        self::assertTrue($table->columns()[0]->isAutoIncrement());
        self::assertTrue($table->columns()[0]->isPrimary());
        self::assertSame(ReverseIndexSchema::TYPE_PRIMARY, $table->indexes()[0]->type());
        self::assertSame(['1'], $table->indexes()[0]->columns());
        self::assertSame(ReverseIndexSchema::TYPE_INDEX, $table->indexes()[1]->type());
        self::assertSame(['title'], $table->indexes()[1]->columns());
        self::assertSame('users', $table->foreignKeys()[0]->referencedTable());
        self::assertSame(['1'], $table->foreignKeys()[0]->columns());
        self::assertSame(['1'], $table->foreignKeys()[0]->referencedColumns());
        self::assertSame('CASCADE', $table->foreignKeys()[0]->onDelete());
        self::assertSame('NO ACTION', $table->foreignKeys()[0]->onUpdate());
    }

    public function testSqliteInspectorReadsTablesColumnsIndexesAndForeignKeys(): void
    {
        $schema = $this->makeManager()->schema();
        $schema->statement(
            'CREATE TABLE "reports" ('
            . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '"user_id" INTEGER NOT NULL, '
            . '"slug" VARCHAR(120) NOT NULL, '
            . '"score" DECIMAL(9, 3) NOT NULL DEFAULT 10.125, '
            . 'CONSTRAINT "reports_user_id_foreign" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE ON UPDATE RESTRICT'
            . ')',
        );
        $schema->statement('CREATE UNIQUE INDEX "reports_slug_unique" ON "reports" ("slug")');

        $inspector = new SqliteSchemaInspector($this->makeManager());
        $tables = $inspector->tables();
        $definition = $inspector->table('reports');

        self::assertSame(['reports', 'users'], $tables);
        self::assertSame('reports', $definition->name());
        self::assertCount(4, $definition->columns());
        self::assertSame('bigInteger', $definition->columns()[0]->type());
        self::assertTrue($definition->columns()[0]->isAutoIncrement());
        self::assertSame('string', $definition->columns()[2]->type());
        self::assertSame(120, $definition->columns()[2]->option('length'));
        self::assertSame('decimal', $definition->columns()[3]->type());
        self::assertSame(9, $definition->columns()[3]->option('precision'));
        self::assertSame(3, $definition->columns()[3]->option('scale'));
        self::assertCount(2, $definition->indexes());
        self::assertSame('primary', $definition->indexes()[0]->name());
        self::assertSame('reports_slug_unique', $definition->indexes()[1]->name());
        self::assertCount(1, $definition->foreignKeys());
        self::assertSame('reports_fk_0', $definition->foreignKeys()[0]->name());
        self::assertSame('CASCADE', $definition->foreignKeys()[0]->onDelete());
        self::assertSame('RESTRICT', $definition->foreignKeys()[0]->onUpdate());
    }

    public function testForeignKeyDefinitionValidationCoversAdditionalBranches(): void
    {
        try {
            new ForeignKeyDefinition([], 'users_bad_foreign');
            self::fail('Expected empty foreign key columns exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Foreign key columns cannot be empty.', $exception->getMessage());
        }

        try {
            new ForeignKeyDefinition(['id'], ' ');
            self::fail('Expected empty foreign key name exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Foreign key name cannot be empty.', $exception->getMessage());
        }

        $foreign = new ForeignKeyDefinition(['profile_id'], 'users_profile_foreign');

        try {
            $foreign->on(' ');
            self::fail('Expected empty foreign key table exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Foreign key table cannot be empty.', $exception->getMessage());
        }

        try {
            $foreign->references([]);
            self::fail('Expected empty foreign key reference list exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Foreign key referenced columns cannot be empty.', $exception->getMessage());
        }

        try {
            $foreign->references(['id', ' ']);
            self::fail('Expected invalid foreign key reference column exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Foreign key column names must be non-empty strings.', $exception->getMessage());
        }
    }

    public function testTableDifferDetectsMismatchesChangedColumnsIndexesAndForeignKeys(): void
    {
        $differ = new TableDiffer();

        try {
            $differ->diff(new TableSchema('users', [], [], []), new TableSchema('posts', [], [], []));
            self::fail('Expected table mismatch exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Cannot diff table "users" against "posts".', $exception->getMessage());
        }

        $before = new TableSchema('users', [
            new ColumnSchema('status', 'string', false, null, false, false, false, false, ['length' => 50]),
        ], [
            new ReverseIndexSchema('users_status_index', ReverseIndexSchema::TYPE_INDEX, ['status']),
        ], [
            new ReverseForeignKeySchema('users_profile_foreign', ['profile_id'], 'profiles', ['id'], 'CASCADE', 'RESTRICT'),
        ]);
        $after = new TableSchema('users', [
            new ColumnSchema('status', 'string', false, 'active', true, false, false, true, ['length' => 120]),
        ], [
            new ReverseIndexSchema('users_status_index', ReverseIndexSchema::TYPE_UNIQUE, ['status']),
        ], [
            new ReverseForeignKeySchema('users_profile_foreign', ['profile_id'], 'profiles', ['id'], 'SET NULL', 'CASCADE'),
        ]);

        $diff = $differ->diff($before, $after);

        self::assertTrue($diff->hasChangedColumns());
        self::assertCount(1, $diff->changedColumns());
        self::assertSame('status', $diff->changedColumns()[0]->from()->name());
        self::assertSame('status', $diff->changedColumns()[0]->to()->name());
        self::assertSame('users_status_index', $diff->addedIndexes()[0]->name());
        self::assertSame('users_status_index', $diff->droppedIndexes()[0]->name());
        self::assertSame('users_profile_foreign', $diff->addedForeignKeys()[0]->name());
        self::assertSame('users_profile_foreign', $diff->droppedForeignKeys()[0]->name());
    }

    public function testReverseEngineerCanRenderCompositeDefinitionsAndGeneratedNames(): void
    {
        $table = new TableSchema('audit-log_entries', [
            new ColumnSchema('id', 'bigInteger', false, null, false, true, true, true),
            new ColumnSchema('account_id', 'bigInteger', false, null),
            new ColumnSchema('region_id', 'bigInteger', false, null),
            new ColumnSchema('created_at', 'timestamp', false, 'CURRENT_TIMESTAMP', true),
            new ColumnSchema('enabled', 'boolean', false, true, true),
        ], [
            new ReverseIndexSchema('audit_log_entries_lookup_unique', ReverseIndexSchema::TYPE_UNIQUE, ['account_id', 'region_id']),
        ], [
            new ReverseForeignKeySchema(
                'audit_log_entries_account_region_foreign',
                ['account_id', 'region_id'],
                'regions',
                ['account_id', 'id'],
                'CASCADE',
                'RESTRICT',
            ),
        ]);

        $reverseEngineer = new ReverseEngineer(
            $this->makeManager(),
            null,
            new class ($table) implements SchemaInspectorInterface
            {
                public function __construct(private readonly TableSchema $table)
                {
                }

                public function table(string $table): TableSchema
                {
                    return $this->table;
                }

                public function tables(): array
                {
                    return [$this->table->name()];
                }
            },
        );

        $migration = $reverseEngineer->migration('audit-log_entries');

        self::assertStringContainsString('final class CreateAuditLogEntriesTable extends Migration', $migration);
        self::assertStringContainsString("\$table->timestamp('created_at')->useCurrent();", $migration);
        self::assertStringContainsString("\$table->boolean('enabled')->default(true);", $migration);
        self::assertStringContainsString("\$table->unique(['account_id', 'region_id'], 'audit_log_entries_lookup_unique');", $migration);
        self::assertStringContainsString("\$table->foreign(['account_id', 'region_id'], 'audit_log_entries_account_region_foreign')->references(['account_id', 'id'])->on('regions')->onDelete('cascade')->onUpdate('restrict');", $migration);
    }

    public function testSchemaCanGenerateModelSourceFromLiveTable(): void
    {
        $schema = $this->makeManager()->schema(self::CONNECTION_ALIAS);

        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120);
            $table->json('meta')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps();
        });

        $source = $schema->modelFromTable('posts', 'App\\Models\\Post');

        self::assertStringContainsString('namespace App\\Models;', $source);
        self::assertStringContainsString('use Myxa\\Database\\Model\\HasTimestamps;', $source);
        self::assertStringContainsString('final class Post extends Model', $source);
        self::assertStringContainsString("protected string \$table = 'posts';", $source);
        self::assertStringContainsString("protected ?string \$connection = 'schema-test';", $source);
        self::assertStringContainsString('protected ?int $id = null;', $source);
        self::assertStringContainsString('protected string $title;', $source);
        self::assertStringContainsString('protected ?string $meta = null;', $source);
        self::assertStringContainsString('protected int $published = 0;', $source);
        self::assertStringNotContainsString('protected ?string $created_at = null;', $source);
    }

    public function testSchemaCanGenerateModelSourceFromMigrationBlueprint(): void
    {
        $migration = new class extends Migration
        {
            public function connectionName(): ?string
            {
                return 'audit';
            }

            public function up(Schema $schema): void
            {
                $schema->create('audit_entries', function (Blueprint $table): void {
                    $table->string('uuid')->primary();
                    $table->dateTime('processed_at')->nullable();
                    $table->decimal('score', 9, 3)->default(1.5);
                });
            }
        };

        $source = $this->makeManager()->schema()->modelFromMigration($migration, 'AuditEntry', namespace: 'App\\Models');

        self::assertStringContainsString('namespace App\\Models;', $source);
        self::assertStringContainsString('use DateTimeImmutable;', $source);
        self::assertStringContainsString('use Myxa\\Database\\Attributes\\Cast;', $source);
        self::assertStringContainsString("protected string \$table = 'audit_entries';", $source);
        self::assertStringContainsString("protected string \$primaryKey = 'uuid';", $source);
        self::assertStringContainsString("protected ?string \$connection = 'audit';", $source);
        self::assertStringContainsString('protected string $uuid;', $source);
        self::assertStringContainsString('#[Cast(CastType::DateTimeImmutable)]', $source);
        self::assertStringContainsString('protected ?DateTimeImmutable $processed_at = null;', $source);
        self::assertStringContainsString('protected float $score = 1.5;', $source);
    }

    public function testModelGenerationValidationErrorsAreHelpful(): void
    {
        $generator = new ModelGenerator();

        try {
            $generator->generate(new TableSchema('users', [], [], []), ' ');
            self::fail('Expected empty class name exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Model class name cannot be empty.', $exception->getMessage());
        }

        try {
            $generator->generate(new TableSchema('users', [], [], []), 'Bad-Class');
            self::fail('Expected invalid class name exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Invalid model class name "Bad-Class".', $exception->getMessage());
        }

        try {
            $generator->generate(new TableSchema('users', [], [
                new ReverseIndexSchema('primary', ReverseIndexSchema::TYPE_PRIMARY, ['tenant_id', 'id']),
            ], []), 'User');
            self::fail('Expected composite primary key exception.');
        } catch (LogicException $exception) {
            self::assertSame('Model generation does not support composite primary keys for table "users".', $exception->getMessage());
        }

        try {
            $generator->generate(new TableSchema('odd-table', [
                new ColumnSchema('bad-column', 'string', false),
            ], [], []), 'OddModel');
            self::fail('Expected invalid property name exception.');
        } catch (LogicException $exception) {
            self::assertSame('Cannot generate a model property for column "bad-column"; it is not a valid PHP property name.', $exception->getMessage());
        }
    }

    public function testModelGeneratorMapsNormalizedSchemaTypesToProperties(): void
    {
        $generator = new ModelGenerator();
        $source = $generator->generate(new TableSchema('events', [
            new ColumnSchema('id', 'bigInteger', false, null, false, false, true, true),
            new ColumnSchema('is_active', 'boolean', false, true, true),
            new ColumnSchema('payload', 'json', true),
            new ColumnSchema('published_at', 'timestamp', true),
        ], [], []), 'App\\Models\\Event');

        self::assertStringContainsString('namespace App\\Models;', $source);
        self::assertStringContainsString('use Myxa\\Database\\Attributes\\Cast;', $source);
        self::assertStringContainsString('use Myxa\\Database\\Model\\CastType;', $source);
        self::assertStringContainsString('protected ?int $id = null;', $source);
        self::assertStringContainsString('protected bool $is_active = true;', $source);
        self::assertStringContainsString('#[Cast(CastType::Json)]', $source);
        self::assertStringContainsString('protected ?array $payload = null;', $source);
        self::assertStringContainsString('#[Cast(CastType::DateTimeImmutable)]', $source);
        self::assertStringContainsString('protected ?DateTimeImmutable $published_at = null;', $source);
    }

    public function testModelFromMigrationSelectionErrorsAreHelpful(): void
    {
        $schema = $this->makeManager()->schema();

        $emptyMigration = new class extends Migration
        {
            public function up(Schema $schema): void
            {
            }
        };

        try {
            $schema->modelFromMigration($emptyMigration, 'EmptyModel');
            self::fail('Expected missing blueprint exception.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('did not record any schema blueprints', $exception->getMessage());
        }

        $multiMigration = new class extends Migration
        {
            public function up(Schema $schema): void
            {
                $schema->create('users', function (Blueprint $table): void {
                    $table->id();
                });
                $schema->create('posts', function (Blueprint $table): void {
                    $table->id();
                });
            }
        };

        try {
            $schema->modelFromMigration($multiMigration, 'AnyModel');
            self::fail('Expected ambiguous blueprint exception.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('defines multiple schema blueprints', $exception->getMessage());
        }

        try {
            $schema->modelFromMigration($multiMigration, 'PostModel', 'missing');
            self::fail('Expected missing table blueprint exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('does not define a blueprint for table "missing"', $exception->getMessage());
        }

        $alterOnlyMigration = new class extends Migration
        {
            public function up(Schema $schema): void
            {
                $schema->table('users', function (Blueprint $table): void {
                    $table->string('nickname')->nullable();
                });
            }
        };

        try {
            $schema->modelFromMigration($alterOnlyMigration, 'UserModel');
            self::fail('Expected alter-only model generation exception.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('requires a create() blueprint', $exception->getMessage());
        }
    }

    public function testSnapshotAccessorsAndFailureCases(): void
    {
        $table = new TableSchema('users', [
            new ColumnSchema('id', 'bigInteger', false, null, false, false, true, true),
        ], [
            new ReverseIndexSchema('primary', ReverseIndexSchema::TYPE_PRIMARY, ['id']),
        ], []);

        $snapshot = SchemaSnapshot::fromTables([$table], 'main', 'sqlite', '2026-04-07T00:00:00+00:00');

        self::assertTrue($snapshot->hasTable('users'));
        self::assertFalse($snapshot->hasTable('missing'));
        self::assertSame('main', $snapshot->connection());
        self::assertSame('sqlite', $snapshot->driver());
        self::assertSame('2026-04-07T00:00:00+00:00', $snapshot->capturedAt());
        self::assertSame(['users'], $snapshot->tableNames());
        self::assertSame('users', $snapshot->tables()[0]->name());
        self::assertArrayHasKey('tables', $snapshot->toArray());

        try {
            $snapshot->table('missing');
            self::fail('Expected missing snapshot table exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Snapshot does not contain table "missing".', $exception->getMessage());
        }

        try {
            SchemaSnapshot::fromJson('{"tables":[123]}');
            self::fail('Expected invalid snapshot table definition exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Snapshot table definition must be an array.', $exception->getMessage());
        }

        try {
            SchemaSnapshot::fromJson('{"tables":[{"name":"users","columns":[123]}]}');
            self::fail('Expected invalid snapshot column definition exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Snapshot column definition must be an array.', $exception->getMessage());
        }

        try {
            SchemaSnapshot::fromJson('{"tables":[{"name":"users","indexes":[123]}]}');
            self::fail('Expected invalid snapshot index definition exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Snapshot index definition must be an array.', $exception->getMessage());
        }

        try {
            SchemaSnapshot::fromJson('{"tables":[{"name":"users","foreign_keys":[123]}]}');
            self::fail('Expected invalid snapshot foreign key definition exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Snapshot foreign key definition must be an array.', $exception->getMessage());
        }
    }

    public function testReverseEngineerDefaultsAndPrimaryKeyDiffErrors(): void
    {
        $schema = $this->makeManager()->schema();
        $schema->create('labels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $migration = $schema->reverseEngineer()->migration('labels');
        self::assertStringContainsString('final class CreateLabelsTable extends Migration', $migration);

        $before = new TableSchema('users', [], [
            new ReverseIndexSchema('primary', ReverseIndexSchema::TYPE_PRIMARY, ['id']),
        ], []);
        $after = new TableSchema('users', [], [], []);

        try {
            $schema->reverseEngineer()->alterMigration($before, $after);
            self::fail('Expected primary key diff exception.');
        } catch (LogicException $exception) {
            self::assertSame('Alter migration generation does not yet support dropping primary keys.', $exception->getMessage());
        }
    }

    private function makeManager(): DatabaseManager
    {
        return new DatabaseManager(self::CONNECTION_ALIAS);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tableInfo(string $table): array
    {
        $statement = $this->pdo()->query(sprintf('PRAGMA table_info("%s")', str_replace('"', '""', $table)));

        return $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function indexList(string $table): array
    {
        $statement = $this->pdo()->query(sprintf('PRAGMA index_list("%s")', str_replace('"', '""', $table)));

        return $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function foreignKeyList(string $table): array
    {
        $statement = $this->pdo()->query(sprintf('PRAGMA foreign_key_list("%s")', str_replace('"', '""', $table)));

        return $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function pdo(): PDO
    {
        return PdoConnection::get(self::CONNECTION_ALIAS)->getPdo();
    }

    private function makeMysqlInspectorConnection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('DATABASE', static fn (): string => 'app');
        $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec(
            'CREATE TABLE information_schema.columns ('
            . 'TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT, COLUMN_TYPE TEXT, DATA_TYPE TEXT, '
            . 'IS_NULLABLE TEXT, COLUMN_DEFAULT TEXT NULL, EXTRA TEXT, COLUMN_KEY TEXT, '
            . 'CHARACTER_MAXIMUM_LENGTH INTEGER NULL, NUMERIC_PRECISION INTEGER NULL, NUMERIC_SCALE INTEGER NULL, '
            . 'ORDINAL_POSITION INTEGER'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE information_schema.statistics ('
            . 'TABLE_SCHEMA TEXT, TABLE_NAME TEXT, INDEX_NAME TEXT, NON_UNIQUE INTEGER, '
            . 'COLUMN_NAME TEXT, SEQ_IN_INDEX INTEGER'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE information_schema.key_column_usage ('
            . 'CONSTRAINT_SCHEMA TEXT, TABLE_SCHEMA TEXT, TABLE_NAME TEXT, CONSTRAINT_NAME TEXT, COLUMN_NAME TEXT, '
            . 'REFERENCED_TABLE_NAME TEXT NULL, REFERENCED_COLUMN_NAME TEXT NULL, ORDINAL_POSITION INTEGER'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE information_schema.referential_constraints ('
            . 'CONSTRAINT_SCHEMA TEXT, CONSTRAINT_NAME TEXT, UPDATE_RULE TEXT, DELETE_RULE TEXT'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE information_schema.tables ('
            . 'TABLE_SCHEMA TEXT, TABLE_NAME TEXT, TABLE_TYPE TEXT'
            . ')',
        );

        $pdo->exec(
            "INSERT INTO information_schema.tables VALUES "
            . "('app', 'posts', 'BASE TABLE'), ('app', 'users', 'BASE TABLE')",
        );
        $pdo->exec(
            "INSERT INTO information_schema.columns VALUES "
            . "('app', 'posts', 'id', 'bigint unsigned', 'bigint', 'NO', NULL, 'auto_increment', 'PRI', NULL, 20, 0, 1), "
            . "('app', 'posts', 'user_id', 'bigint unsigned', 'bigint', 'NO', NULL, '', '', NULL, 20, 0, 2), "
            . "('app', 'posts', 'title', 'varchar(120)', 'varchar', 'NO', NULL, '', '', 120, NULL, NULL, 3), "
            . "('app', 'posts', 'score', 'decimal(9,3)', 'decimal', 'NO', '10.125', '', '', NULL, 9, 3, 4)",
        );
        $pdo->exec(
            "INSERT INTO information_schema.statistics VALUES "
            . "('app', 'posts', 'PRIMARY', 0, 'id', 1), "
            . "('app', 'posts', 'posts_title_unique', 0, 'title', 1)",
        );
        $pdo->exec(
            "INSERT INTO information_schema.key_column_usage VALUES "
            . "('app', 'app', 'posts', 'posts_user_id_foreign', 'user_id', 'users', 'id', 1)",
        );
        $pdo->exec(
            "INSERT INTO information_schema.referential_constraints VALUES "
            . "('app', 'posts_user_id_foreign', 'RESTRICT', 'CASCADE')",
        );

        return $this->connectionFromPdo($pdo);
    }

    private function makePostgresInspectorConnection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('current_schema', static fn (): string => 'public');
        $pdo->sqliteCreateAggregate(
            'array_agg',
            static function (?array $context, mixed $value): array {
                $context ??= [];
                $context[] = (string) $value;

                return $context;
            },
            static fn (?array $context): string => '{' . implode(',', $context ?? []) . '}',
            1,
        );
        $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec(
            'CREATE TABLE information_schema.columns ('
            . 'table_schema TEXT, table_name TEXT, column_name TEXT, data_type TEXT, is_nullable TEXT, '
            . 'column_default TEXT NULL, character_maximum_length INTEGER NULL, numeric_precision INTEGER NULL, '
            . 'numeric_scale INTEGER NULL, ordinal_position INTEGER'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE information_schema.key_column_usage ('
            . 'table_schema TEXT, table_name TEXT, constraint_name TEXT, column_name TEXT, ordinal_position INTEGER'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE information_schema.table_constraints ('
            . 'table_schema TEXT, table_name TEXT, constraint_name TEXT, constraint_type TEXT'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE information_schema.constraint_column_usage ('
            . 'constraint_schema TEXT, constraint_name TEXT, table_name TEXT, column_name TEXT'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE information_schema.referential_constraints ('
            . 'constraint_schema TEXT, constraint_name TEXT, update_rule TEXT, delete_rule TEXT'
            . ')',
        );
        $pdo->exec(
            'CREATE TABLE information_schema.tables ('
            . 'table_schema TEXT, table_name TEXT, table_type TEXT'
            . ')',
        );
        $pdo->exec('CREATE TABLE pg_indexes (schemaname TEXT, tablename TEXT, indexname TEXT, indexdef TEXT)');

        $pdo->exec(
            "INSERT INTO information_schema.tables VALUES "
            . "('public', 'posts', 'BASE TABLE'), ('public', 'users', 'BASE TABLE')",
        );
        $pdo->exec(
            "INSERT INTO information_schema.columns VALUES "
            . "('public', 'posts', 'id', 'bigint', 'NO', 'nextval(''posts_id_seq''::regclass)', NULL, 64, 0, 1), "
            . "('public', 'posts', 'user_id', 'bigint', 'NO', NULL, NULL, 64, 0, 2), "
            . "('public', 'posts', 'title', 'character varying', 'NO', NULL, 120, NULL, NULL, 3)",
        );
        $pdo->exec(
            "INSERT INTO information_schema.table_constraints VALUES "
            . "('public', 'posts', 'posts_pkey', 'PRIMARY KEY'), "
            . "('public', 'posts', 'posts_user_id_foreign', 'FOREIGN KEY')",
        );
        $pdo->exec(
            "INSERT INTO information_schema.key_column_usage VALUES "
            . "('public', 'posts', 'posts_pkey', 'id', 'id'), "
            . "('public', 'posts', 'posts_user_id_foreign', 'user_id', 'user_id')",
        );
        $pdo->exec(
            "INSERT INTO information_schema.constraint_column_usage VALUES "
            . "('public', 'posts_user_id_foreign', 'users', 'id')",
        );
        $pdo->exec(
            "INSERT INTO information_schema.referential_constraints VALUES "
            . "('public', 'posts_user_id_foreign', 'NO ACTION', 'CASCADE')",
        );
        $pdo->exec(
            "INSERT INTO pg_indexes VALUES "
            . "('public', 'posts', 'posts_title_idx', 'CREATE INDEX posts_title_idx ON posts USING btree (title)')",
        );

        return $this->connectionFromPdo($pdo);
    }

    private function connectionFromPdo(PDO $pdo): PdoConnection
    {
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

    private function makeInMemoryConnection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(
            'CREATE TABLE users ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'email TEXT NOT NULL, '
            . 'status TEXT NOT NULL'
            . ')',
        );
        $pdo->exec(
            "INSERT INTO users (email, status) VALUES "
            . "('john@example.com', 'active'), "
            . "('anna@example.com', 'inactive'), "
            . "('jane@example.com', 'active')",
        );

        return $this->connectionFromPdo($pdo);
    }
}
