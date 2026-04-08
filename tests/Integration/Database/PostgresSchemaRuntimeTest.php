<?php

declare(strict_types=1);

namespace Test\Integration\Database;

use Myxa\Database\Connection\PdoConnection;
use Myxa\Database\Connection\PdoConnectionConfig;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Schema\Blueprint;
use Myxa\Database\Schema\ReverseEngineering\ColumnSchema;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostgresSchemaRuntimeTest extends TestCase
{
    private const string CONNECTION_ALIAS = 'pgsql-runtime';

    protected function setUp(): void
    {
        if (getenv('MYXA_PGSQL_TEST_ENABLED') !== '1') {
            $this->markTestSkipped('PostgreSQL runtime tests are disabled.');
        }

        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension is not available.');
        }

        PdoConnection::unregister(self::CONNECTION_ALIAS, false);
        PdoConnection::register(self::CONNECTION_ALIAS, $this->makeConnection(), true);

        $pdo = PdoConnection::get(self::CONNECTION_ALIAS)->getPdo();
        $pdo->exec('DROP TABLE IF EXISTS "posts"');
        $pdo->exec('DROP TABLE IF EXISTS "users"');
    }

    protected function tearDown(): void
    {
        if (PdoConnection::has(self::CONNECTION_ALIAS)) {
            $pdo = PdoConnection::get(self::CONNECTION_ALIAS)->getPdo();
            $pdo->exec('DROP TABLE IF EXISTS "posts"');
            $pdo->exec('DROP TABLE IF EXISTS "users"');
        }

        PdoConnection::unregister(self::CONNECTION_ALIAS, false);
    }

    public function testSchemaExecutesAgainstRealPostgresConnection(): void
    {
        $schema = new DatabaseManager(self::CONNECTION_ALIAS)->schema();

        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->timestamps();
        });

        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('title');
            $table->json('meta')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        self::assertTrue($schema->statement(
            'INSERT INTO "users" ("email") VALUES (?)',
            ['john@example.com'],
        ));
        self::assertTrue($schema->statement(
            'INSERT INTO "posts" ("user_id", "title", "meta") VALUES (?, ?, ?::jsonb)',
            [1, 'First post', '{"published":true}'],
        ));

        $schema->table('posts', function (Blueprint $table): void {
            $table->string('slug', 120)->nullable()->unique();
            $table->renameColumn('title', 'headline');
        });

        $rows = $this->pdo()->query(
            'SELECT "headline", "slug", "meta"::text AS meta_text FROM "posts" ORDER BY "id" ASC',
        );

        self::assertNotFalse($rows);
        $post = $rows->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($post);
        self::assertSame('First post', $post['headline']);
        self::assertNull($post['slug']);
        self::assertSame('{"published": true}', $post['meta_text']);
    }

    public function testSchemaCanReverseEngineerRealPostgresTableDefinitions(): void
    {
        $schema = new DatabaseManager(self::CONNECTION_ALIAS)->schema();

        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });

        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('title', 120);
            $table->json('meta')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('restrict');
        });

        $definition = $schema->reverseEngineer()->table('posts');
        $migration = $schema->reverseEngineer()->migration('posts', 'CreatePostsTable');

        self::assertSame(
            ['posts', 'users'],
            $schema->reverseEngineer()->tables(),
        );
        self::assertSame(
            ['id', 'user_id', 'title', 'meta'],
            array_map(static fn (ColumnSchema $column): string => $column->name(), $definition->columns()),
        );
        self::assertSame('bigInteger', $definition->columns()[0]->type());
        self::assertTrue($definition->columns()[0]->isAutoIncrement());
        self::assertTrue($definition->columns()[0]->isPrimary());
        self::assertSame('json', $definition->columns()[3]->type());
        self::assertSame('users', $definition->foreignKeys()[0]->referencedTable());
        self::assertSame('CASCADE', $definition->foreignKeys()[0]->onDelete());
        self::assertSame('RESTRICT', $definition->foreignKeys()[0]->onUpdate());
        self::assertStringContainsString("\$table->json('meta')->nullable();", $migration);
        self::assertStringContainsString("->onDelete('cascade')->onUpdate('restrict');", $migration);
    }

    public function testSchemaCanDiffRealPostgresTableChanges(): void
    {
        $schema = new DatabaseManager(self::CONNECTION_ALIAS)->schema();

        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });

        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('title', 120)->unique();
            $table->json('meta')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        $before = $schema->reverseEngineer()->table('posts');

        $schema->statement('ALTER TABLE "posts" DROP CONSTRAINT "posts_user_id_foreign"');
        $schema->statement('DROP INDEX "posts_title_unique"');
        $schema->statement('ALTER TABLE "posts" DROP COLUMN "meta"');
        $schema->table('posts', function (Blueprint $table): void {
            $table->string('slug', 120)->nullable()->unique();
        });

        $after = $schema->reverseEngineer()->table('posts');
        $diff = $schema->reverseEngineer()->diff($before, $after);
        $migration = $schema->reverseEngineer()->alterMigration($before, $after, 'AlterPostsTable');

        self::assertTrue($diff->hasChanges());
        self::assertSame('slug', $diff->addedColumns()[0]->name());
        self::assertSame('meta', $diff->droppedColumns()[0]->name());
        self::assertSame('posts_title_unique', $diff->droppedIndexes()[0]->name());
        self::assertSame('posts_user_id_foreign', $diff->droppedForeignKeys()[0]->name());
        self::assertStringContainsString("\$table->dropForeign('posts_user_id_foreign');", $migration);
        self::assertStringContainsString("\$table->dropIndex('posts_title_unique');", $migration);
        self::assertStringContainsString("\$table->dropColumn('meta');", $migration);
        self::assertStringContainsString("\$table->string('slug', 120)->nullable();", $migration);
        self::assertStringContainsString("\$table->unique('slug', 'posts_slug_unique');", $migration);
    }

    public function testSchemaCanDiffStoredSnapshotAgainstLivePostgresSchema(): void
    {
        $schema = new DatabaseManager(self::CONNECTION_ALIAS)->schema();

        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });

        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('title', 120)->unique();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        $snapshot = $schema->reverseEngineer()->snapshot();
        $json = $snapshot->toJson();
        $loaded = $schema->reverseEngineer()->snapshotFromJson($json);

        $schema->statement('ALTER TABLE "posts" DROP CONSTRAINT "posts_user_id_foreign"');
        $schema->statement('DROP INDEX "posts_title_unique"');
        $schema->table('posts', function (Blueprint $table): void {
            $table->string('slug', 120)->nullable()->unique();
        });

        $live = $schema->reverseEngineer()->table('posts');
        $diff = $schema->reverseEngineer()->diff($loaded->table('posts'), $live);
        $migration = $schema->reverseEngineer()->alterMigration($loaded->table('posts'), $live, 'AlterPostsTable');

        self::assertSame(['posts', 'users'], $loaded->tableNames());
        self::assertTrue($diff->hasChanges());
        self::assertSame('slug', $diff->addedColumns()[0]->name());
        self::assertSame('posts_title_unique', $diff->droppedIndexes()[0]->name());
        self::assertSame('posts_user_id_foreign', $diff->droppedForeignKeys()[0]->name());
        self::assertStringContainsString('"driver": "pgsql"', $json);
        self::assertStringContainsString("\$table->dropForeign('posts_user_id_foreign');", $migration);
        self::assertStringContainsString("\$table->unique('slug', 'posts_slug_unique');", $migration);
    }

    private function makeConnection(): PdoConnection
    {
        return new PdoConnection(new PdoConnectionConfig(
            engine: 'pgsql',
            database: getenv('MYXA_PGSQL_TEST_DATABASE') ?: 'myxa_test',
            host: getenv('MYXA_PGSQL_TEST_HOST') ?: 'postgres',
            port: (int) (getenv('MYXA_PGSQL_TEST_PORT') ?: 5432),
            username: getenv('MYXA_PGSQL_TEST_USERNAME') ?: 'myxa',
            password: getenv('MYXA_PGSQL_TEST_PASSWORD') ?: 'secret',
        ));
    }

    private function pdo(): PDO
    {
        return PdoConnection::get(self::CONNECTION_ALIAS)->getPdo();
    }
}
