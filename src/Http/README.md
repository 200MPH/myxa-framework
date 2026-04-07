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
$token = Request::bearerToken();
$isJson = Request::expectsJson();
```

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
Response::text('Created', 201);
Response::html('<h1>Hello</h1>');
Response::redirect('/login');
Response::noContent();
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
        return Response::json(['path' => $request->path()]);
    }
}
```

## Notes

- `RequestServiceProvider` registers the current request
- `ResponseServiceProvider` registers the shared response
- `ExceptionHandlerServiceProvider` binds the default exception renderer/reporter
