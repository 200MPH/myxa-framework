<?php

declare(strict_types=1);

namespace Test\Unit;

use InvalidArgumentException;
use LogicException;
use Myxa\Application;
use Myxa\Container\BindingResolutionException;
use Myxa\Container\Container;
use Myxa\Container\NotFoundException;
use Myxa\Support\ServiceProvider;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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

    public function testContainerBindCreatesFreshInstancesAndExplicitInstancesOverrideBindings(): void
    {
        $app = new Application();
        $app->bind(ApplicationTestTransientService::class);

        $first = $app->make(ApplicationTestTransientService::class);
        $second = $app->make(ApplicationTestTransientService::class);

        self::assertNotSame($first, $second);
        self::assertNotSame($first->dependency, $second->dependency);
        self::assertTrue($app->has(ApplicationTestTransientService::class));
        self::assertFalse($app->has('missing-transient'));

        $instance = new ApplicationTestTransientService(new ApplicationTestDependency());
        $app->instance(ApplicationTestTransientService::class, $instance);

        self::assertSame($instance, $app->make(ApplicationTestTransientService::class));
    }

    public function testContainerCallSupportsClosuresObjectMethodsAndStaticMethods(): void
    {
        $app = new Application();
        $target = new ApplicationTestCallableTarget();

        self::assertSame(
            'closure:Myxa:app',
            $app->call(
                static fn (Application $app, string $name = 'guest'): string => sprintf(
                    'closure:%s:%s',
                    $name,
                    $app instanceof Application ? 'app' : 'missing',
                ),
                ['name' => 'Myxa'],
            ),
        );

        self::assertSame(
            'instance:7',
            $app->call([$target, 'instanceMethod'], ['value' => 7]),
        );

        self::assertSame(
            'static:guest',
            $app->call(ApplicationTestCallableTarget::class . '::staticMethod'),
        );
    }

    public function testContainerRejectsInvalidConcreteTargetsAndCircularDependencies(): void
    {
        $app = new Application();
        $app->bind('missing-target', 'MissingTarget');

        try {
            $app->make('missing-target');
            self::fail('Expected BindingResolutionException for missing target.');
        } catch (BindingResolutionException $exception) {
            self::assertSame('Target [MissingTarget] is not instantiable.', $exception->getMessage());
        }

        $app->bind('abstract-target', ApplicationTestAbstractService::class);

        try {
            $app->make('abstract-target');
            self::fail('Expected BindingResolutionException for abstract target.');
        } catch (BindingResolutionException $exception) {
            self::assertSame(
                sprintf('Target [%s] is not instantiable.', ApplicationTestAbstractService::class),
                $exception->getMessage(),
            );
        }

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage(
            sprintf('Circular dependency detected while resolving [%s].', ApplicationTestCircularA::class),
        );

        $app->make(ApplicationTestCircularA::class);
    }

    public function testApplicationCanBootLateProvidersAndAvoidDuplicateRegistration(): void
    {
        $app = new Application();
        $app->boot();

        $provider = new ApplicationTestLateProvider();
        $registered = $app->register($provider);

        self::assertSame($provider, $registered);
        self::assertSame(1, $provider->registerCalls);
        self::assertSame(1, $provider->bootCalls);
        self::assertSame($provider, $app->getProvider(ApplicationTestLateProvider::class));
        self::assertSame($provider, $app->register(ApplicationTestLateProvider::class));
        self::assertSame(1, $provider->registerCalls);
        self::assertSame(1, $provider->bootCalls);
    }

    public function testApplicationSkipsAlreadyBootedProvidersAndAllowsDefaultNoOpBoot(): void
    {
        $app = new Application();
        $provider = new ApplicationTestProvider();
        $app->register($provider);

        $bootedProviders = new ReflectionProperty(Application::class, 'bootedProviders');
        $bootedProviders->setValue($app, [ApplicationTestProvider::class => true]);

        $app->boot();
        $app->boot();

        self::assertFalse($provider->booted);
        self::assertTrue($app->isBooted());

        (new ApplicationTestNoopProvider())->boot();
    }

    public function testApplicationRejectsInvalidProviderClassName(): void
    {
        $app = new Application();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Provider [%s] must extend %s.', \stdClass::class, ServiceProvider::class));

        $app->register(\stdClass::class);
    }

    public function testServiceProviderThrowsWhenApplicationIsMissing(): void
    {
        $provider = new ApplicationTestLateProvider();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            sprintf('Service provider [%s] is not attached to an application.', ApplicationTestLateProvider::class),
        );

        $provider->exposeApplication();
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

final readonly class ApplicationTestTransientService
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

final class ApplicationTestCallableTarget
{
    public function instanceMethod(Application $app, int $value): string
    {
        return sprintf('instance:%d', $value);
    }

    public static function staticMethod(string $name = 'guest'): string
    {
        return sprintf('static:%s', $name);
    }
}

abstract class ApplicationTestAbstractService
{
}

final class ApplicationTestCircularA
{
    public function __construct(public ApplicationTestCircularB $dependency)
    {
    }
}

final class ApplicationTestCircularB
{
    public function __construct(public ApplicationTestCircularA $dependency)
    {
    }
}

final class ApplicationTestLateProvider extends ServiceProvider
{
    public int $registerCalls = 0;

    public int $bootCalls = 0;

    public function register(): void
    {
        $this->registerCalls++;
    }

    public function boot(): void
    {
        $this->bootCalls++;
    }

    public function exposeApplication(): Application
    {
        return $this->app();
    }
}

final class ApplicationTestNoopProvider extends ServiceProvider
{
    public function register(): void
    {
    }
}
