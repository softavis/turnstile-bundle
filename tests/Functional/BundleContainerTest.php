<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Softavis\Turnstile\HttpTurnstileVerifier;
use Softavis\Turnstile\TurnstileVerifier;
use Softavis\TurnstileBundle\Tests\StubVerifier;

final class BundleContainerTest extends TestCase
{
    public function testContainerCompilesWithoutSecurityAndHonorsVerifierOverride(): void
    {
        $kernel = new TestKernel([
            'enabled' => true,
            'site_key' => 'public-key',
            'secret' => 'private-secret',
        ]);
        $kernel->boot();
        try {
            self::assertInstanceOf(StubVerifier::class, $kernel->getContainer()->get(TurnstileVerifier::class));
            self::assertNotNull($kernel->getContainer()->get('test.form_factory'));
            $formThemes = $kernel->getContainer()->getParameter('twig.form.resources');
            self::assertContains('@Test/custom_form_theme.html.twig', $formThemes);
            self::assertContains('@SoftavisTurnstile/form/turnstile_widget.html.twig', $formThemes);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testContainerCompilesWithOptionalSecurity(): void
    {
        $kernel = new TestKernel([
            'enabled' => true,
            'site_key' => 'public-key',
            'secret' => 'private-secret',
        ], true);
        $kernel->boot();
        try {
            self::assertNotNull($kernel->getContainer()->get('test.service_container')->get('security.firewall.map'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testEnabledModeRejectsMissingCredentials(): void
    {
        $kernel = new TestKernel([]);
        try {
            $kernel->boot();
            self::fail('Missing credentials must fail bundle configuration.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('site_key and softavis_turnstile.secret', $exception->getMessage());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testDisabledModeUsesNoOpVerifierAlias(): void
    {
        $kernel = new TestKernel(['enabled' => false], false, false);
        $kernel->boot();
        try {
            self::assertInstanceOf(
                \Softavis\TurnstileBundle\Service\DisabledTurnstileVerifier::class,
                $kernel->getContainer()->get('test.verifier_inspection'),
            );
        } finally {
            $kernel->shutdown();
        }
    }

    public function testDefaultTransportExplicitlyBuildsCoreVerifier(): void
    {
        $kernel = new TestKernel([
            'enabled' => true,
            'site_key' => 'public-key',
            'secret' => 'private-secret',
        ], false, false);
        $kernel->boot();
        try {
            self::assertInstanceOf(
                HttpTurnstileVerifier::class,
                $kernel->getContainer()->get('test.verifier_inspection'),
            );
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAutomaticLoginRequiresAnExpectedAction(): void
    {
        $kernel = new TestKernel([
            'enabled' => true,
            'site_key' => 'public-key',
            'secret' => 'private-secret',
            'login' => ['enabled' => true],
        ]);
        try {
            $kernel->boot();
            self::fail('Password-login protection requires a server-side action.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('softavis_turnstile.login.action is required', $exception->getMessage());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testEmptyHostnameAllowlistIsRejected(): void
    {
        $kernel = new TestKernel([
            'enabled' => true,
            'site_key' => 'public-key',
            'secret' => 'private-secret',
            'expected_hostnames' => [],
        ]);
        try {
            $kernel->boot();
            self::fail('An empty hostname allowlist is not a valid configured policy.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('expected_hostnames must be a non-empty list', $exception->getMessage());
        } finally {
            $kernel->shutdown();
        }
    }
}
