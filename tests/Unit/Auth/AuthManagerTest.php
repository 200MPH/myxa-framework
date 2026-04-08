<?php

declare(strict_types=1);

namespace Test\Unit\Auth;

use InvalidArgumentException;
use Myxa\Application;
use Myxa\Auth\AuthGuardInterface;
use Myxa\Auth\AuthManager;
use Myxa\Auth\AuthServiceProvider;
use Myxa\Auth\BearerTokenGuard;
use Myxa\Auth\BearerTokenResolverInterface;
use Myxa\Auth\SessionGuard;
use Myxa\Auth\SessionUserResolverInterface;
use Myxa\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthManager::class)]
#[CoversClass(AuthServiceProvider::class)]
#[CoversClass(BearerTokenGuard::class)]
#[CoversClass(SessionGuard::class)]
final class AuthManagerTest extends TestCase
{
    public function testProviderRegistersDefaultGuardsAndAliases(): void
    {
        $app = new Application();
        $app->register(AuthServiceProvider::class);
        $app->boot();

        $manager = $app->make(AuthManager::class);

        self::assertInstanceOf(AuthManager::class, $manager);
        self::assertSame($manager, $app->make('auth'));
        self::assertInstanceOf(SessionGuard::class, $manager->guard('web'));
        self::assertInstanceOf(BearerTokenGuard::class, $manager->guard('api'));
    }

    public function testAuthManagerCachesResolvedUsersPerRequestAndGuard(): void
    {
        $app = new Application();
        $manager = new AuthManager($app);
        $guard = new AuthManagerTestGuard();
        $manager->extend('web', $guard);
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/profile',
        ]);

        self::assertSame('user-1', $manager->user($request, 'web'));
        self::assertSame('user-1', $manager->user($request, 'web'));
        self::assertSame(1, $guard->calls);
    }

    public function testGuardsResolveUsersFromSessionAndBearerTokenInputs(): void
    {
        $sessionGuard = new SessionGuard(new class implements SessionUserResolverInterface
        {
            public function resolve(string $sessionId, Request $request): mixed
            {
                return $sessionId === 'sess-1' ? ['id' => 10] : null;
            }
        });
        $apiGuard = new BearerTokenGuard(new class implements BearerTokenResolverInterface
        {
            public function resolve(string $token, Request $request): mixed
            {
                return $token === 'token-1' ? ['id' => 20] : null;
            }
        });

        $webRequest = new Request(
            cookies: ['session' => 'sess-1'],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/dashboard'],
        );
        $apiRequest = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/me',
            'HTTP_AUTHORIZATION' => 'Bearer token-1',
        ]);

        self::assertSame(['id' => 10], $sessionGuard->user($webRequest));
        self::assertSame(['id' => 20], $apiGuard->user($apiRequest));
        self::assertTrue($sessionGuard->check($webRequest));
        self::assertTrue($apiGuard->check($apiRequest));
    }

    public function testAuthManagerCanSwapDefaultGuardsAndClearCachedUsers(): void
    {
        $app = new Application();
        $manager = new AuthManager($app);
        $webGuard = new AuthManagerTestGuard();
        $apiGuard = new AuthManagerTestGuard('user-2');
        $manager->extend('web', $webGuard);
        $manager->extend('api', $apiGuard);
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/me',
        ]);

        self::assertSame('web', $manager->defaultGuard());
        self::assertSame('user-1', $manager->user($request));
        $manager->shouldUse('api');
        self::assertSame('api', $manager->defaultGuard());
        self::assertSame('user-2', $manager->user($request));
        self::assertTrue($manager->check($request));

        $replacement = new AuthManagerTestGuard('user-3');
        $manager->extend('api', $replacement);

        self::assertSame('user-3', $manager->user($request, 'api'));
        self::assertSame(1, $replacement->calls);
    }

    public function testAuthManagerRejectsMissingAndInvalidGuards(): void
    {
        $app = new Application();
        $manager = new AuthManager($app);

        try {
            $manager->guard('missing');
            self::fail('Expected missing guard exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Authentication guard [missing] is not registered.', $exception->getMessage());
        }

        $app->instance('broken.guard', new class
        {
        });
        $manager->extend('broken', 'broken.guard');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Authentication guard [broken.guard] must implement %s.',
            AuthGuardInterface::class,
        ));

        $manager->guard('broken');
    }
}

final class AuthManagerTestGuard implements AuthGuardInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $user = 'user-1')
    {
    }

    public function user(Request $request): mixed
    {
        $this->calls++;

        return $this->user;
    }

    public function check(Request $request): bool
    {
        return $this->user($request) !== null;
    }
}
