<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests\Functional;

use Softavis\Turnstile\TurnstileVerifier;
use Softavis\TurnstileBundle\SoftavisTurnstileBundle;
use Softavis\TurnstileBundle\Tests\StubVerifier;
use GuzzleHttp\Psr7\HttpFactory;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    private static int $kernelCounter = 0;
    private readonly string $cacheDir;

    /**
     * @param array<string, mixed> $turnstileConfig
     */
    public function __construct(
        private readonly array $turnstileConfig,
        private readonly bool $withSecurity = false,
        private readonly bool $overrideVerifier = true,
        private readonly bool $withApplicationHttpAliases = false,
    ) {
        parent::__construct('test', false);
        $this->cacheDir = sys_get_temp_dir().'/softavis-turnstile-tests/'.getmypid().'-'.++self::$kernelCounter;
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        if ($this->withSecurity) {
            yield new SecurityBundle();
        }
        yield new SoftavisTurnstileBundle();
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'test-secret',
            'test' => true,
            'validation' => ['enabled' => true, 'enable_attributes' => true],
            'translator' => ['enabled' => true],
            'assets' => ['base_path' => '/application'],
        ]);
        $container->loadFromExtension('twig', [
            'form_themes' => ['@Test/custom_form_theme.html.twig'],
        ]);
        if ($this->withSecurity) {
            $container->loadFromExtension('security', [
                'firewalls' => [
                    'main' => [
                        'lazy' => true,
                        'form_login' => ['login_path' => '/login', 'check_path' => '/login'],
                    ],
                ],
            ]);
        }

        $config = $this->turnstileConfig;
        if ($this->withApplicationHttpAliases) {
            $container->register('test.application_http_client', MockHttpClient::class)->setPublic(true);
            $container->register('test.application_psr17_factory', HttpFactory::class)->setPublic(true);
            $container->register('test.application_psr18_client', Psr18Client::class)
                ->setArguments([
                    new Reference('test.application_http_client'),
                    new Reference('test.application_psr17_factory'),
                    new Reference('test.application_psr17_factory'),
                ])
                ->setPublic(true);
            $container->setAlias(HttpClientInterface::class, 'test.application_http_client')->setPublic(true);
            $container->setAlias(ClientInterface::class, 'test.application_psr18_client')->setPublic(true);
            $container->setAlias(RequestFactoryInterface::class, 'test.application_psr17_factory')->setPublic(true);
            $container->setAlias(ResponseFactoryInterface::class, 'test.application_psr17_factory')->setPublic(true);
            $container->setAlias(StreamFactoryInterface::class, 'test.application_psr17_factory')->setPublic(true);
        }
        if ($this->overrideVerifier) {
            $container->register('test.turnstile_verifier', StubVerifier::class)->setPublic(true);
            $container->setAlias(TurnstileVerifier::class, 'test.turnstile_verifier')->setPublic(true);
            $config += ['verifier_service' => 'test.turnstile_verifier'];
        } else {
            $container->setAlias('test.verifier_inspection', TurnstileVerifier::class)->setPublic(true);
        }
        $container->setAlias('test.form_factory', 'form.factory')->setPublic(true);
        $container->loadFromExtension('softavis_turnstile', $config);
    }

    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }
}
