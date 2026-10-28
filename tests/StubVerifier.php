<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests;

use Softavis\Turnstile\TurnstileVerifier;
use Softavis\Turnstile\VerificationRequest;
use Softavis\Turnstile\VerificationResult;

final class StubVerifier implements TurnstileVerifier
{
    /** @var list<VerificationRequest> */
    public array $requests;
    public VerificationResult|\Throwable $response;

    public function __construct()
    {
        $this->requests = [];
        $this->response = new VerificationResult(true);
    }

    public function verify(VerificationRequest $request): VerificationResult
    {
        $this->requests[] = $request;
        if ($this->response instanceof \Throwable) {
            throw $this->response;
        }

        return $this->response;
    }

    public function reset(): void
    {
        $this->requests = [];
        $this->response = new VerificationResult(true);
    }
}
