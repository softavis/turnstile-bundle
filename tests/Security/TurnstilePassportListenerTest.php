<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests\Security;

use PHPUnit\Framework\TestCase;
use Softavis\Turnstile\Exception\TransportException;
use Softavis\Turnstile\VerificationResult;
use Softavis\TurnstileBundle\Security\TurnstileBadge;
use Softavis\TurnstileBundle\Security\TurnstilePassportListener;
use Softavis\TurnstileBundle\Tests\StubVerifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Translation\IdentityTranslator;

final class TurnstilePassportListenerTest extends TestCase
{
    public function testAutomaticLoginVerificationPrecedesLazyUserLookup(): void
    {
        $verifier = new StubVerifier();
        $requestStack = new RequestStack();
        $request = Request::create('/login', 'POST', ['cf-turnstile-response' => 'login-token']);
        $request->attributes->set('_firewall', 'main');
        $requestStack->push($request);
        $listener = $this->createListener($verifier, $requestStack, [
            'enabled' => true,
            'action' => 'login',
            'token_field' => 'cf-turnstile-response',
            'firewalls' => ['main'],
        ]);
        $loads = 0;
        $passport = new Passport(
            new UserBadge('alice', static function () use (&$loads): InMemoryUser {
                ++$loads;

                return new InMemoryUser('alice', 'password');
            }),
            new PasswordCredentials('password'),
        );

        $listener->checkPassport(new CheckPassportEvent(new TestLoginAuthenticator(), $passport));

        self::assertCount(1, $verifier->requests);
        self::assertSame('login-token', $verifier->requests[0]->response);
        self::assertSame('login', $verifier->requests[0]->expectedAction);
        self::assertSame(0, $loads);
    }

    public function testStandardFormLoginAuthenticatorIsProtected(): void
    {
        $verifier = new StubVerifier();
        $requestStack = new RequestStack();
        $request = Request::create('/login', 'POST', ['cf-turnstile-response' => 'standard-login-token']);
        $requestStack->push($request);
        $listener = $this->createListener($verifier, $requestStack, [
            'enabled' => true,
            'action' => 'login',
            'token_field' => 'cf-turnstile-response',
            'firewalls' => [],
        ]);
        $passport = new Passport(new UserBadge('alice'), new PasswordCredentials('password'));

        $listener->checkPassport(new CheckPassportEvent(
            $this->createMock(FormLoginAuthenticator::class),
            $passport,
        ));

        self::assertCount(1, $verifier->requests);
        self::assertSame('standard-login-token', $verifier->requests[0]->response);
    }

    public function testInvalidCsrfPreventsTurnstileVerification(): void
    {
        $verifier = new StubVerifier();
        $requestStack = new RequestStack();
        $request = Request::create('/login', 'POST', ['cf-turnstile-response' => 'token']);
        $request->attributes->set('_firewall', 'main');
        $requestStack->push($request);
        $listener = $this->createListener($verifier, $requestStack, [
            'enabled' => true,
            'action' => 'login',
            'token_field' => 'cf-turnstile-response',
            'firewalls' => [],
        ]);
        $event = new CheckPassportEvent(
            new TestLoginAuthenticator(),
            new Passport(new UserBadge('alice'), new PasswordCredentials('password')),
        );
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(CheckPassportEvent::class, static function (): never {
            throw new InvalidCsrfTokenException('Invalid CSRF token.');
        }, 512);
        $dispatcher->addListener(CheckPassportEvent::class, $listener->checkPassport(...), 384);

        try {
            $dispatcher->dispatch($event);
            self::fail('CSRF rejection must stop passport verification.');
        } catch (InvalidCsrfTokenException) {
            self::assertCount(0, $verifier->requests);
        }
    }

    public function testLoginThrottlingRunsBeforeTurnstile(): void
    {
        $verifier = new StubVerifier();
        $requestStack = new RequestStack();
        $request = Request::create('/login', 'POST', ['cf-turnstile-response' => 'token']);
        $request->attributes->set('_firewall', 'main');
        $requestStack->push($request);
        $listener = $this->createListener($verifier, $requestStack, [
            'enabled' => true,
            'action' => 'login',
            'token_field' => 'cf-turnstile-response',
            'firewalls' => [],
        ]);
        $event = new CheckPassportEvent(
            new TestLoginAuthenticator(),
            new Passport(new UserBadge('alice'), new PasswordCredentials('password')),
        );
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(CheckPassportEvent::class, static function (): never {
            throw new \RuntimeException('throttled');
        }, 2080);
        $dispatcher->addListener(CheckPassportEvent::class, $listener->checkPassport(...), 384);

        try {
            $dispatcher->dispatch($event);
            self::fail('Throttled logins must stop before Turnstile.');
        } catch (\RuntimeException $exception) {
            self::assertSame('throttled', $exception->getMessage());
            self::assertCount(0, $verifier->requests);
        }
    }

    public function testVerifierRejectionAndOperationalFailureUseGenericAuthError(): void
    {
        foreach ([
            new VerificationResult(false),
            new TransportException(new \RuntimeException('secret transport detail')),
        ] as $response) {
            $verifier = new StubVerifier();
            $verifier->response = $response;
            $requestStack = new RequestStack();
            $request = Request::create('/login', 'POST', ['cf-turnstile-response' => 'token']);
            $request->attributes->set('_firewall', 'main');
            $requestStack->push($request);
            $listener = $this->createListener($verifier, $requestStack, [
                'enabled' => true,
                'action' => 'login',
                'token_field' => 'cf-turnstile-response',
                'firewalls' => [],
            ]);

            try {
                $listener->checkPassport(new CheckPassportEvent(
                    new TestLoginAuthenticator(),
                    new Passport(new UserBadge('alice'), new PasswordCredentials('password')),
                ));
                self::fail('Invalid verification must reject authentication.');
            } catch (CustomUserMessageAuthenticationException $exception) {
                self::assertSame('softavis_turnstile.authentication_failed', $exception->getMessage());
                self::assertStringNotContainsString('secret transport detail', $exception->getMessage());
            }
        }
    }

    public function testMissingTokenRejectsWithoutCallingVerifier(): void
    {
        $verifier = new StubVerifier();
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/login', 'POST'));
        $listener = $this->createListener($verifier, $requestStack, [
            'enabled' => true,
            'action' => 'login',
            'token_field' => 'cf-turnstile-response',
            'firewalls' => [],
        ]);

        try {
            $listener->checkPassport(new CheckPassportEvent(
                new TestLoginAuthenticator(),
                new Passport(new UserBadge('alice'), new PasswordCredentials('password')),
            ));
            self::fail('A missing login token must reject authentication.');
        } catch (CustomUserMessageAuthenticationException) {
            self::assertCount(0, $verifier->requests);
        }
    }

    public function testBadgeOptInVerifiesOnceAndResolvesOnlyOnAcceptance(): void
    {
        $verifier = new StubVerifier();
        $requestStack = new RequestStack();
        $listener = $this->createListener($verifier, $requestStack, [
            'enabled' => true,
            'action' => null,
            'token_field' => 'ignored',
            'firewalls' => [],
        ]);
        $badge = new TurnstileBadge('custom-token', 'custom-login');
        $passport = new Passport(new UserBadge('alice'), new \Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials(
            static fn (): bool => true,
            'credentials',
        ), [$badge]);

        $listener->checkPassport(new CheckPassportEvent(
            $this->createMock(AuthenticatorInterface::class),
            $passport,
        ));

        self::assertCount(1, $verifier->requests);
        self::assertSame('custom-token', $verifier->requests[0]->response);
        self::assertSame('custom-login', $verifier->requests[0]->expectedAction);
        self::assertTrue($badge->isResolved());
    }

    public function testRejectedBadgeIsNotResolved(): void
    {
        $verifier = new StubVerifier();
        $verifier->response = new VerificationResult(false);
        $listener = $this->createListener($verifier, new RequestStack(), [
            'enabled' => true,
            'action' => null,
            'token_field' => 'unused',
            'firewalls' => [],
        ]);
        $badge = new TurnstileBadge('invalid-token', 'custom-login');
        $passport = new Passport(
            new UserBadge('alice'),
            new \Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials(
                static fn (): bool => true,
                'credentials',
            ),
            [$badge],
        );

        try {
            $listener->checkPassport(new CheckPassportEvent(
                $this->createMock(AuthenticatorInterface::class),
                $passport,
            ));
            self::fail('A rejected Turnstile token must reject authentication.');
        } catch (CustomUserMessageAuthenticationException) {
            self::assertFalse($badge->isResolved());
        }
    }

    public function testDisabledVerificationResolvesBadgeWithoutVerifying(): void
    {
        $verifier = new StubVerifier();
        $listener = $this->createListener($verifier, new RequestStack(), [
            'enabled' => false,
            'action' => null,
            'token_field' => 'unused',
            'firewalls' => [],
        ], false);
        $badge = new TurnstileBadge('', 'custom-login');
        $passport = new Passport(
            new UserBadge('alice'),
            new \Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials(
                static fn (): bool => true,
                'credentials',
            ),
            [$badge],
        );

        $listener->checkPassport(new CheckPassportEvent(
            $this->createMock(AuthenticatorInterface::class),
            $passport,
        ));

        self::assertTrue($badge->isResolved());
        self::assertCount(0, $verifier->requests);
    }

    public function testFirewallScopeAndUnrelatedAuthenticatorAreUnaffected(): void
    {
        $verifier = new StubVerifier();
        $requestStack = new RequestStack();
        $request = Request::create('/login', 'POST', ['cf-turnstile-response' => 'token']);
        $request->attributes->set('_firewall', 'api');
        $requestStack->push($request);
        $listener = $this->createListener($verifier, $requestStack, [
            'enabled' => true,
            'action' => 'login',
            'token_field' => 'cf-turnstile-response',
            'firewalls' => ['main'],
        ]);
        $passport = new Passport(new UserBadge('alice'), new PasswordCredentials('password'));
        $listener->checkPassport(new CheckPassportEvent(new TestLoginAuthenticator(), $passport));
        $listener->checkPassport(new CheckPassportEvent(
            $this->createMock(AuthenticatorInterface::class),
            $passport,
        ));

        self::assertCount(0, $verifier->requests);
    }

    private function createListener(
        StubVerifier $verifier,
        RequestStack $requestStack,
        array $login,
        bool $enabled = true,
    ): TurnstilePassportListener {
        return new TurnstilePassportListener(
            $verifier,
            $requestStack,
            new IdentityTranslator(),
            $enabled,
            $login,
            ['example.test'],
            false,
        );
    }
}

final class TestLoginAuthenticator extends AbstractLoginFormAuthenticator
{
    public function authenticate(Request $request): Passport
    {
        return new Passport(new UserBadge('alice'), new PasswordCredentials('password'));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    protected function getLoginUrl(Request $request): string
    {
        return '/login';
    }
}
