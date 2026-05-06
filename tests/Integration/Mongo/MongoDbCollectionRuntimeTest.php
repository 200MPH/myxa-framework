<?php

declare(strict_types=1);

namespace Test\Integration\Mongo;

use Myxa\Mongo\Connection\MongoConnection;
use Myxa\Mongo\MongoManager;
use Myxa\Mongo\MongoModel;
use MongoDB\Client;
use PHPUnit\Framework\TestCase;

final class RuntimeUserDocument extends MongoModel
{
    protected string $collection = 'users';

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Mongo uses _id as the default document key.
    protected string|int|null $_id = null;

    protected string $email = '';

    protected string $status = '';
}

final class MongoDbCollectionRuntimeTest extends TestCase
{
    private string $databaseName = 'myxa_test';

    protected function setUp(): void
    {
        if (getenv('MYXA_MONGO_TEST_ENABLED') !== '1') {
            self::markTestSkipped('MongoDB runtime tests are disabled.');
        }

        if (!extension_loaded('mongodb')) {
            self::markTestSkipped('mongodb extension is not available.');
        }

        if (!class_exists(Client::class)) {
            self::markTestSkipped('mongodb/mongodb package is not installed.');
        }

        $this->databaseName = getenv('MYXA_MONGO_TEST_DATABASE') ?: 'myxa_test';
        $this->dropDatabase();
    }

    protected function tearDown(): void
    {
        if (extension_loaded('mongodb') && class_exists(Client::class)) {
            $this->dropDatabase();
        }

        MongoModel::clearManager();
    }

    public function testMongoCollectionRunsCrudOperationsAgainstRealMongo(): void
    {
        $manager = $this->makeManager();
        $collection = $manager->collection('users');

        $id = $collection->insertOne([
            'email' => 'runtime@example.com',
            'status' => 'active',
        ]);

        self::assertIsString($id);

        $created = $collection->findOne(['_id' => $id]);
        self::assertSame($id, $created['_id'] ?? null);
        self::assertSame('runtime@example.com', $created['email'] ?? null);

        self::assertTrue($collection->updateOne([
            '_id' => $id,
        ], [
            '_id' => $id,
            'email' => 'updated@example.com',
            'status' => 'archived',
        ]));

        self::assertSame('updated@example.com', $collection->findOne(['_id' => $id])['email'] ?? null);
        self::assertTrue($collection->deleteOne(['_id' => $id]));
        self::assertNull($collection->findOne(['_id' => $id]));
    }

    public function testMongoModelPersistsAgainstRealMongo(): void
    {
        RuntimeUserDocument::setManager($this->makeManager());

        $user = RuntimeUserDocument::create([
            'email' => 'model@example.com',
            'status' => 'draft',
        ]);

        $id = $user->getKey();
        self::assertIsString($id);

        $found = RuntimeUserDocument::find($id);
        self::assertInstanceOf(RuntimeUserDocument::class, $found);
        self::assertSame('model@example.com', $found->email);

        $found->status = 'published';
        self::assertTrue($found->save());

        $updated = RuntimeUserDocument::find($id);
        self::assertInstanceOf(RuntimeUserDocument::class, $updated);
        self::assertSame('published', $updated->status);

        self::assertTrue($updated->delete());
        self::assertNull(RuntimeUserDocument::find($id));
    }

    private function makeManager(): MongoManager
    {
        $manager = new MongoManager('runtime');
        $manager->addConnection('runtime', MongoConnection::fromUri(
            uri: getenv('MYXA_MONGO_TEST_URI') ?: 'mongodb://mongo:27017',
            database: $this->databaseName,
        ));

        return $manager;
    }

    private function dropDatabase(): void
    {
        $client = new Client(getenv('MYXA_MONGO_TEST_URI') ?: 'mongodb://mongo:27017');
        $client->selectDatabase($this->databaseName)->drop();
    }
}
