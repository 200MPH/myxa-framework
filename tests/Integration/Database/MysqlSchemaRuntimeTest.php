<?php

declare(strict_types=1);

namespace Test\Integration\Database;

use Myxa\Database\Connection\PdoConnection;
use Myxa\Database\Connection\PdoConnectionConfig;
use Myxa\Database\DatabaseManager;
use Myxa\Database\Schema\Blueprint;
use Myxa\Database\Schema\ReverseEngineering\ColumnSchema;
use PHPUnit\Framework\TestCase;

final class MysqlSchemaRuntimeTest extends TestCase
{
    private const string CONNECTION_ALIAS = 'mysql-runtime';

    protected function setUp(): void
    {
        if (getenv('MYXA_MYSQL_TEST_ENABLED') !== '1') {
            $this->markTestSkipped('MySQL runtime tests are disabled.');
        }

        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not available.');
        }

        PdoConnection::unregister(self::CONNECTION_ALIAS, false);
        PdoConnection::register(self::CONNECTION_ALIAS, $this->makeConnection(), true);

        $pdo = PdoConnection::get(self::CONNECTION_ALIAS)->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DROP TABLE IF EXISTS `posts`');
        $pdo->exec('DROP TABLE IF EXISTS `users`');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function tearDown(): void
    {
        if (PdoConnection::has(self::CONNECTION_ALIAS)) {
            $pdo = PdoConnection::get(self::CONNECTION_ALIAS)->getPdo();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $pdo->exec('DROP TABLE IF EXISTS `posts`');
            $pdo->exec('DROP TABLE IF EXISTS `users`');
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        PdoConnection::unregister(self::CONNECTION_ALIAS, false);
    }

    public function testSchemaCanReverseEngineerRealMysqlTableDefinitions(): void
    {
        $schema = new DatabaseManager(self::CONNECTION_ALIAS)->schema();

        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });

        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unsigned();
            $table->string('title', 120)->unique();
            $table->decimal('score', 9, 3)->default(10.125);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('restrict');
        });

        $definition = $schema->reverseEngineer()->table('posts');
        $migration = $schema->reverseEngineer()->migration('posts', 'CreatePostsTable');

        self::assertSame(['posts', 'users'], $schema->reverseEngineer()->tables());
        self::assertSame(
            ['id', 'user_id', 'title', 'score'],
            array_map(static fn (ColumnSchema $column): string => $column->name(), $definition->columns()),
        );
        self::assertSame('bigInteger', $definition->columns()[0]->type());
        self::assertTrue($definition->columns()[0]->isAutoIncrement());
        self::assertTrue($definition->columns()[0]->isPrimary());
        self::assertTrue($definition->columns()[1]->isUnsigned());
        self::assertSame('decimal', $definition->columns()[3]->type());
        self::assertSame(9, $definition->columns()[3]->option('precision'));
        self::assertSame(3, $definition->columns()[3]->option('scale'));
        self::assertSame('users', $definition->foreignKeys()[0]->referencedTable());
        self::assertSame('CASCADE', $definition->foreignKeys()[0]->onDelete());
        self::assertSame('RESTRICT', $definition->foreignKeys()[0]->onUpdate());
        self::assertStringContainsString("\$table->decimal('score', 9, 3)->default(10.125);", $migration);
        self::assertStringContainsString("->onDelete('cascade')->onUpdate('restrict');", $migration);
    }

    public function testSchemaCanDiffRealMysqlTableChanges(): void
    {
        $schema = new DatabaseManager(self::CONNECTION_ALIAS)->schema();

        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });

        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id')->unsigned();
            $table->string('title', 120)->unique();
            $table->text('body')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        $before = $schema->reverseEngineer()->table('posts');

        $schema->statement('ALTER TABLE `posts` DROP FOREIGN KEY `posts_user_id_foreign`');
        $schema->statement('DROP INDEX `posts_title_unique` ON `posts`');
        $schema->statement('ALTER TABLE `posts` DROP COLUMN `body`');
        $schema->table('posts', function (Blueprint $table): void {
            $table->string('slug', 120)->nullable()->unique();
        });

        $after = $schema->reverseEngineer()->table('posts');
        $diff = $schema->reverseEngineer()->diff($before, $after);
        $migration = $schema->reverseEngineer()->alterMigration($before, $after, 'AlterPostsTable');

        self::assertTrue($diff->hasChanges());
        self::assertSame('slug', $diff->addedColumns()[0]->name());
        self::assertSame('body', $diff->droppedColumns()[0]->name());
        self::assertSame('posts_title_unique', $diff->droppedIndexes()[0]->name());
        self::assertSame('posts_user_id_foreign', $diff->droppedForeignKeys()[0]->name());
        self::assertStringContainsString("\$table->dropForeign('posts_user_id_foreign');", $migration);
        self::assertStringContainsString("\$table->dropIndex('posts_title_unique');", $migration);
        self::assertStringContainsString("\$table->dropColumn('body');", $migration);
        self::assertStringContainsString("\$table->string('slug', 120)->nullable();", $migration);
        self::assertStringContainsString("\$table->unique('slug', 'posts_slug_unique');", $migration);
    }

    private function makeConnection(): PdoConnection
    {
        return new PdoConnection(new PdoConnectionConfig(
            engine: 'mysql',
            database: getenv('MYXA_MYSQL_TEST_DATABASE') ?: 'myxa',
            host: getenv('MYXA_MYSQL_TEST_HOST') ?: 'mysql',
            port: (int) (getenv('MYXA_MYSQL_TEST_PORT') ?: 3306),
            username: getenv('MYXA_MYSQL_TEST_USERNAME') ?: 'myxa',
            password: getenv('MYXA_MYSQL_TEST_PASSWORD') ?: 'myxa',
        ));
    }
}
