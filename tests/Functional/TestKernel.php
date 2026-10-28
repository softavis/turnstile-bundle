<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests\Functional;

use Softavis\Turnstile\TurnstileVerifier;
use Softavis\TurnstileBundle\SoftavisTurnstileBundle;
use Softavis\TurnstileBundle\Tests\StubVerifier;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $turnstileConfig
     */
    public function __construct(
        private readonly array $turnstileConfig,
        private readonly bool $withSecurity = false,
        private readonly bool $overrideVerifier = true,
    ) {
        parent::__construct('test', false);
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
        return sys_get_temp_dir().'/softavis-turnstile-tests/'.spl_object_id($this);
    }

}
