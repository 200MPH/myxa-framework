# Support

Support contains shared framework helpers, service-provider base classes, facades, and storage support wrappers.

## HTML Helpers

Use `Myxa\Support\Html\Html` when you want to render PHP view files for HTML responses:

```php
use Myxa\Support\Html\Html;

$html = new Html(__DIR__ . '/views');

$content = $html->render('pages/home', [
    'title' => 'Dashboard',
    'user' => $user,
]);

$page = $html->renderPage(
    'pages/home',
    ['user' => $user],
    'layouts/app',
    ['title' => 'Dashboard'],
);
```

Inside a template, `$_html` renders nested partials and `$_e` escapes output safely.
Use `renderPage()` when you want a layout to inject a rendered body view into `layouts/app.php` or another layout.

## Facades

Available facades include:

- `DB`
- `Route`
- `Request`
- `Response`
- `Storage`
- `Event`
- `Debug`

## Example

```php
use Myxa\Support\Facades\DB;
use Myxa\Support\Facades\Route;
use Myxa\Support\Facades\Response;

Route::get('/users', static function () {
    $users = DB::select('SELECT id, email FROM users ORDER BY id ASC');

    return Response::json($users);
});
```

## Service Providers

Framework providers extend `ServiceProvider`:

```php
use Myxa\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // bind services
    }
}
```
