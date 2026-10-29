<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests\Transport;

use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Softavis\Turnstile\HttpTurnstileVerifier;
use Softavis\Turnstile\VerificationRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DefaultTransportTest extends TestCase
{
    public function testGuzzleFactoriesAndPsr18ClientSendSiteverifyRequest(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers'], 'body' => $options['body']];

            return new MockResponse('{"success":true,"hostname":"example.com","action":"login"}', [
                'response_headers' => ['Content-Type: application/json'],
            ]);
        });
        $factory = new HttpFactory();
        $verifier = new HttpTurnstileVerifier('secret', new Psr18Client($httpClient, $factory, $factory), $factory, $factory);

        $result = $verifier->verify(new VerificationRequest(
            response: 'token',
            expectedAction: 'login',
            expectedHostnames: ['example.com'],
        ));

        self::assertTrue($result->isAccepted());
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $captured['url']);
        self::assertSame('secret=secret&response=token', self::body($captured['body']));
        self::assertStringContainsString('application/x-www-form-urlencoded', implode(',', $captured['headers']['content-type']));
    }

    private static function body(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }

        $contents = '';
        while (($chunk = $body()) !== '') {
            $contents .= $chunk;
        }

        return $contents;
    }
}
