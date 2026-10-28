<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests\Functional;

use Softavis\Turnstile\TurnstileVerifier;
use Softavis\TurnstileBundle\Security\TurnstilePassportListener;
use Softavis\TurnstileBundle\SoftavisTurnstileBundle;
use Softavis\TurnstileBundle\Tests\StubVerifier;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AuthenticationTestKernel extends Kernel
{
    use MicroKernelTrait;

    private static int $kernelCounter = 0;
    private readonly string $cacheDir;

    /**
     * @param array<string, mixed> $turnstileConfig
     */
    public function __construct(
        private readonly array $turnstileConfig = [],
        private readonly bool $customAuthenticator = false,
        private readonly bool $withApiFirewall = false,
        private readonly bool $withBasicFirewall = false,
    ) {
        parent::__construct('test', false);
        $this->cacheDir = sys_get_temp_dir().'/softavis-turnstile-auth-tests/'.getmypid().'-'.++self::$kernelCounter;
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new SecurityBundle();
        yield new SoftavisTurnstileBundle();
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'authentication-test-secret',
            'test' => true,
            'session' => [
                'handler_id' => null,
                'name' => 'TURNSTILE_TEST_'.self::$kernelCounter,
            ],
            'csrf_protection' => true,
            'assets' => ['base_path' => '/application'],
        ]);

        $mainFirewall = [
            'lazy' => true,
            'provider' => 'test_users',
            'login_throttling' => ['max_attempts' => 1, 'interval' => '1 minute'],
        ];
        if ($this->customAuthenticator) {
            $mainFirewall['custom_authenticators'] = [TestLoginFormAuthenticator::class];
        } else {
            $mainFirewall['form_login'] = [
                'login_path' => '/login',
                'check_path' => '/login',
                'enable_csrf' => true,
                'default_target_path' => '/protected',
                'always_use_default_target_path' => true,
            ];
        }

        $firewalls = [];
        if ($this->withApiFirewall) {
            $firewalls['api'] = [
                'pattern' => '^/api',
                'provider' => 'test_users',
                'form_login' => [
                    'login_path' => '/api/login',
                    'check_path' => '/api/login',
                    'enable_csrf' => true,
                ],
            ];
        }
        if ($this->withBasicFirewall) {
            $firewalls['basic'] = [
                'pattern' => '^/basic',
                'provider' => 'test_users',
                'http_basic' => [],
            ];
        }
        $firewalls['main'] = $mainFirewall;

        $container->loadFromExtension('security', [
            'password_hashers' => [
                InMemoryUser::class => ['algorithm' => 'auto'],
            ],
            'providers' => [
                'test_users' => ['id' => CountingUserProvider::class],
            ],
            'firewalls' => $firewalls,
            'access_control' => [
                ['path' => '^/protected', 'roles' => 'ROLE_USER'],
                ['path' => '^/basic', 'roles' => 'ROLE_USER'],
            ],
        ]);

        $container->register(CountingUserProvider::class)->setPublic(true);
        $container->register(LoginPageController::class)
            ->setArguments([
                new \Symfony\Component\DependencyInjection\Reference('security.csrf.token_manager'),
            ])
            ->addTag('controller.service_arguments')
            ->setPublic(true);
        $container->register(TestLoginFormAuthenticator::class)->setPublic(true);
        $container->register('test.turnstile_verifier', StubVerifier::class)->setPublic(true);
        $container->setAlias(TurnstileVerifier::class, 'test.turnstile_verifier')->setPublic(true);
        $container->setAlias('test.turnstile_listener', TurnstilePassportListener::class)->setPublic(true);

        $config = array_replace_recursive([
            'enabled' => true,
            'site_key' => 'test-site-key',
            'secret' => 'test-private-secret',
            'login' => [
                'enabled' => true,
                'action' => 'login',
                'firewalls' => ['main'],
            ],
            'verifier_service' => 'test.turnstile_verifier',
        ], $this->turnstileConfig);
        $container->loadFromExtension('softavis_turnstile', $config);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('home_page', '/')->controller(LoginPageController::class);
        $routes->add('login_page', '/login')->controller(LoginPageController::class);
        $routes->add('custom_login_page', '/custom-login')->controller(LoginPageController::class);
        $routes->add('api_login_page', '/api/login')->controller(LoginPageController::class);
        $routes->add('basic_page', '/basic')->controller(LoginPageController::class);
        $routes->add('protected_page', '/protected')->controller(LoginPageController::class);
    }

    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }
}
