# Migrations

Migrations are schema-focused classes with `up()` and `down()` methods.

## Example

```php
use Myxa\Database\Migrations\Migration;
use Myxa\Database\Schema\Blueprint;
use Myxa\Database\Schema\Schema;

final class CreatePostsTable extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('posts');
    }
}
```

## Notes

- `up()` is required
- `down()` is optional, but rollback will throw if it is not implemented
- `connectionName()` can target a non-default connection
- `withinTransaction()` allows a future runner to wrap the migration in a transaction

See [Schema](../Schema/README.md) for the fluent API used inside migrations.
