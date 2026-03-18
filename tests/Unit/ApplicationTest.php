<?php

declare(strict_types=1);

namespace Test\Unit;

use Myxa\Application;
use Myxa\Container\BindingResolutionException;
use Myxa\Container\Container;
use Myxa\Container\NotFoundException;
use Myxa\Support\ServiceProvider;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(BindingResolutionException::class)]
#[CoversClass(Container::class)]
#[CoversClass(NotFoundException::class)]
#[CoversClass(ServiceProvider::class)]
final class ApplicationTest extends TestCase
{
    public function testContainerAutowiresDependenciesAndKeepsSingletonsShared(): void
    {
        $app = new Application();
        $app->singleton(ApplicationTestService::class);

        $service = $app->make(ApplicationTestService::class);

        self::assertInstanceOf(ApplicationTestService::class, $service);
        self::assertInstanceOf(ApplicationTestDependency::class, $service->dependency);
        self::assertSame($service, $app->make(ApplicationTestService::class));
    }

    public function testContainerImplementsPsr11GetAndReturnsContainerEntry(): void
    {
        $app = new Application();
        $app->singleton(ApplicationTestService::class);

        $service = $app->get(ApplicationTestService::class);

        self::assertInstanceOf(ApplicationTestService::class, $service);
        self::assertSame($app, $app->get(PsrContainerInterface::class));
    }

    public function testThirdPartyStyleConsumerCanDependOnPsrContainerInterface(): void
    {
        $app = new Application();
        $app->singleton(ApplicationTestService::class);

        $package = $app->make(ApplicationTestThirdPartyPackage::class);

        self::assertInstanceOf(ApplicationTestThirdPartyPackage::class, $package);
        self::assertInstanceOf(PsrContainerInterface::class, $package->container);
        self::assertInstanceOf(ApplicationTestService::class, $package->resolveService());
    }

    public function testContainerThrowsForUnresolvableScalarDependency(): void
    {
        $app = new Application();

        $this->expectException(BindingResolutionException::class);

        $app->make(ApplicationTestNeedsScalar::class);
    }

    public function testContainerThrowsPsrNotFoundExceptionForUnknownEntry(): void
    {
        $app = new Application();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Container entry [missing-service] was not found.');

        $app->get('missing-service');
    }

    public function testApplicationRegistersAndBootsProviders(): void
    {
        $app = new Application();
        $provider = new ApplicationTestProvider();

        $registered = $app->register($provider);

        self::assertSame($provider, $registered);
        self::assertSame($provider, $app->getProvider(ApplicationTestProvider::class));
        self::assertFalse($provider->booted);
        self::assertFalse($app->isBooted());

        $app->boot();

        self::assertTrue($provider->booted);
        self::assertTrue($app->isBooted());
        self::assertInstanceOf(ApplicationTestService::class, $app->make(ApplicationTestService::class));
    }
}

final class ApplicationTestDependency
{
}

final readonly class ApplicationTestService
{
    public function __construct(public ApplicationTestDependency $dependency)
    {
    }
}

final readonly class ApplicationTestThirdPartyPackage
{
    public function __construct(public PsrContainerInterface $container)
    {
    }

    public function resolveService(): object
    {
        return $this->container->get(ApplicationTestService::class);
    }
}

final readonly class ApplicationTestNeedsScalar
{
    public function __construct(public string $name)
    {
    }
}

final class ApplicationTestProvider extends ServiceProvider
{
    public bool $booted = false;

    public function register(): void
    {
        $this->app()->singleton(ApplicationTestService::class);
    }

    public function boot(): void
    {
        $this->booted = true;
    }
}
