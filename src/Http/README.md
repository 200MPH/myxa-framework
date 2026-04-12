# HTTP

The HTTP layer centers on the request, response, exception handling, and controller base class.

Available facades:

- `Request`
- `Response`

## Request

```php
use Myxa\Support\Facades\Request;

$method = Request::method();
$allInput = Request::all();
$page = Request::query('page', 1);
$email = Request::post('email');
$search = Request::input('search');
$sessionId = Request::cookie('session');
$token = Request::bearerToken();
$isJson = Request::expectsJson();
```

Common access patterns:

```php
use Myxa\Support\Facades\Request;

// Query string: /users?page=2&filter=active
$page = Request::query('page', 1);
$filter = Request::query('filter');
$allQuery = Request::query();

// POST body fields
$email = Request::post('email');
$password = Request::post('password');
$allPost = Request::post();

// Merged query + POST input
$search = Request::input('search');
$allInput = Request::all();

// Cookies
$sessionId = Request::cookie('session');
$theme = Request::cookie('theme', 'light');
$allCookies = Request::cookie();

// Headers are case-insensitive
$contentType = Request::header('Content-Type');
$acceptLanguage = Request::header('accept-language', 'en');
$allHeaders = Request::headers();

// Uploaded files and server values
$avatar = Request::file('avatar');
$remoteAddress = Request::server('REMOTE_ADDR');
```

Request metadata helpers:

```php
use Myxa\Support\Facades\Request;

$path = Request::path();               // /users/list
$requestUri = Request::requestUri();   // /users/list?page=2
$queryString = Request::queryString(); // page=2
$url = Request::url();                 // https://example.com/users/list
$fullUrl = Request::fullUrl();         // https://example.com/users/list?page=2

$scheme = Request::scheme();           // http or https
$isSecure = Request::secure();
$host = Request::host();
$port = Request::port();
$ip = Request::ip();

$isAjax = Request::ajax();
$expectsJson = Request::expectsJson();
$rawBody = Request::content();
```

Useful notes:

- `Request::query('key', $default)`, `Request::post('key', $default)`, `Request::input('key', $default)`, `Request::cookie('key', $default)`, `Request::file('key', $default)`, and `Request::header('name', $default)` all support a default value.
- `Request::input()` and `Request::all()` merge query and POST data. When the same key exists in both places, POST wins.
- `Request::expectsJson()` returns `true` for JSON `Accept` or `Content-Type` headers, AJAX requests, and `/api` routes.

## Response

```php
use Myxa\Support\Facades\Response;

return Response::json([
    'ok' => true,
    'user' => ['id' => 1],
], 200);
```

Other helpers:

```php
use Myxa\Support\Facades\Response;

Response::text('Created', 201);
Response::html('<h1>Hello</h1>');
Response::redirect('/login');
Response::noContent();
```

Headers and cookies can be chained onto the response:

```php
use Myxa\Support\Facades\Response;

return Response::json([
    'ok' => true,
    'user' => ['id' => 1],
], 200)
    ->setHeader('X-Trace-Id', 'req-123')
    ->cookie(
        name: 'session',
        value: 'token-123',
        expires: time() + 3600,
        path: '/',
        domain: '',
        secure: true,
        httpOnly: true,
        sameSite: 'Strict',
    );
```

You can also build a plain response manually:

```php
use Myxa\Http\Response;

return (new Response())
    ->status(202)
    ->setHeader('X-App', 'myxa')
    ->body('Accepted');
```

Cookie helpers:

```php
use Myxa\Support\Facades\Response;

Response::cookie('theme', 'forest');
Response::hasCookie('theme');
Response::cookies();
Response::removeCookie('theme');
```

## Controllers

```php
use Myxa\Http\Controller;
use Myxa\Http\Request;
use Myxa\Support\Facades\Response;

final class UserController extends Controller
{
    protected function get(Request $request): mixed
    {
        return Response::json([
            'path' => $request->path(),
            'page' => $request->query('page', 1),
            'session' => $request->cookie('session'),
        ]);
    }
}
```

## Notes

- `RequestServiceProvider` registers the current request
- `ResponseServiceProvider` registers the shared response
- `ExceptionHandlerServiceProvider` binds the default exception renderer/reporter
- request headers are case-insensitive
- response cookies support `expires`, `path`, `domain`, `secure`, `httpOnly`, and `sameSite`
