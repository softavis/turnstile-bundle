<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Softavis\Turnstile\Exception\TransportException;
use Softavis\Turnstile\VerificationResult;
use Softavis\TurnstileBundle\Security\TurnstileBadge;
use Softavis\TurnstileBundle\Tests\StubVerifier;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SecurityAuthenticationTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormLoginAcceptsValidCredentialsAfterTurnstileVerification(): void
    {
        [$client, $verifier, $provider] = $this->createClient();
        $this->submitFormLogin($client, 'correct-password', 'valid-token');

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/protected', parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_PATH));
        self::assertCount(1, $verifier->requests);
        self::assertSame('login', $verifier->requests[0]->expectedAction);
        self::assertSame(1, $provider->loads);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMissingOrRejectedTokensPreventLazyUserLookup(): void
    {
        [$missingClient, $missingVerifier, $missingProvider] = $this->createClient();
        $missingLoadsBeforeRequest = $missingProvider->loads;
        $this->submitFormLogin($missingClient, 'correct-password', null);

        self::assertSame(302, $missingClient->getResponse()->getStatusCode());
        self::assertCount(0, $missingVerifier->requests);
        self::assertSame($missingLoadsBeforeRequest, $missingProvider->loads);

        [$rejectedClient, $rejectedVerifier, $rejectedProvider] = $this->createClient();
        $rejectedLoadsBeforeRequest = $rejectedProvider->loads;
        $rejectedVerifier->response = new VerificationResult(false);
        $this->submitFormLogin($rejectedClient, 'correct-password', 'rejected-token');

        self::assertSame(302, $rejectedClient->getResponse()->getStatusCode());
        self::assertCount(1, $rejectedVerifier->requests);
        self::assertSame($rejectedLoadsBeforeRequest, $rejectedProvider->loads);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOperationalFailureRejectsLoginBeforeUserLookup(): void
    {
        [$client, $verifier, $provider] = $this->createClient();
        $loadsBeforeRequest = $provider->loads;
        $verifier->response = new TransportException(new \RuntimeException('private transport details'));

        $this->submitFormLogin($client, 'correct-password', 'token');

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertCount(1, $verifier->requests);
        self::assertSame($loadsBeforeRequest, $provider->loads);
        self::assertStringNotContainsString('private transport details', $client->getResponse()->getContent());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInvalidCsrfAndThrottledAttemptsDoNotCallVerifier(): void
    {
        [$csrfClient, $csrfVerifier, $csrfProvider] = $this->createClient();
        $csrfLoadsBeforeRequest = $csrfProvider->loads;
        $csrfToken = $this->getCsrfToken($csrfClient, '/login');
        $csrfClient->request('POST', '/login', [
            '_username' => 'alice',
            '_password' => 'correct-password',
            '_csrf_token' => 'invalid-csrf-token',
            'cf-turnstile-response' => 'token',
        ]);

        self::assertCount(0, $csrfVerifier->requests);
        self::assertSame($csrfLoadsBeforeRequest, $csrfProvider->loads);
        self::assertNotSame('', $csrfToken);

        [$client, $verifier, $provider] = $this->createClient();
        $this->submitFormLogin($client, 'wrong-password', 'first-attempt');
        self::assertCount(1, $verifier->requests);
        self::assertSame(1, $provider->loads);

        $this->submitFormLogin($client, 'wrong-password', 'throttled-attempt');
        self::assertCount(1, $verifier->requests);
        self::assertSame(1, $provider->loads);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFirewallScopeAndHttpBasicRemainUnaffected(): void
    {
        [$client, $verifier, $provider] = $this->createClient([], false, true, true);
        $this->submitFormLogin($client, 'correct-password', 'api-token', '/api/login');

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertCount(0, $verifier->requests);
        self::assertSame(1, $provider->loads);

        $client->request('GET', '/basic', server: [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('alice:correct-password'),
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $verifier->requests);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCustomLoginFormAuthenticatorIsProtected(): void
    {
        [$client, $verifier, $provider] = $this->createClient([], true);
        $this->submitFormLogin($client, 'correct-password', 'custom-token', '/custom-login');

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertCount(1, $verifier->requests);
        self::assertSame('custom-token', $verifier->requests[0]->response);
        self::assertSame(1, $provider->loads);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDisabledModeResolvesExplicitBadgeWithoutVerification(): void
    {
        [$client, $verifier] = $this->createClient([
            'enabled' => false,
            'login' => ['enabled' => false],
        ], true);
        $listener = $client->getContainer()->get('test.turnstile_listener');
        $badge = new TurnstileBadge('', 'custom-login');
        $passport = new \Symfony\Component\Security\Http\Authenticator\Passport\Passport(
            new \Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge('alice'),
            new \Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials(
                static fn (): bool => true,
                'test',
            ),
            [$badge],
        );
        $event = new \Symfony\Component\Security\Http\Event\CheckPassportEvent(
            new TestLoginFormAuthenticator(),
            $passport,
        );

        $listener->checkPassport($event);

        self::assertTrue($badge->isResolved());
        self::assertCount(0, $verifier->requests);
    }

    /**
     * @param array<string, mixed> $turnstileConfig
     * @return array{KernelBrowser, StubVerifier, CountingUserProvider}
     */
    private function createClient(
        array $turnstileConfig = [],
        bool $customAuthenticator = false,
        bool $withApiFirewall = false,
        bool $withBasicFirewall = false,
    ): array {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $kernel = new AuthenticationTestKernel($turnstileConfig, $customAuthenticator, $withApiFirewall, $withBasicFirewall);
        $client = new KernelBrowser($kernel);
        $client->disableReboot();
        $client->request('GET', '/login');

        $container = $client->getContainer();

        return [
            $client,
            $container->get('test.turnstile_verifier'),
            $container->get(CountingUserProvider::class),
        ];
    }

    private function submitFormLogin(KernelBrowser $client, string $password, ?string $token, string $path = '/login'): void
    {
        $csrfToken = $this->getCsrfToken($client, $path);
        $data = [
            '_username' => 'alice',
            '_password' => $password,
            '_csrf_token' => $csrfToken,
        ];
        if ($token !== null) {
            $data['cf-turnstile-response'] = $token;
        }

        $client->request('POST', $path, $data);
    }

    private function getCsrfToken(KernelBrowser $client, string $path): string
    {
        if ($client->getRequest()->getPathInfo() !== $path) {
            $client->request('GET', $path);
        }
        preg_match('/name="_csrf_token" value="([^"]+)"/', $client->getResponse()->getContent() ?: '', $matches);

        return $matches[1] ?? '';
    }
}
