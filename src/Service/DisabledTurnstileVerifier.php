<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Service;

use Softavis\Turnstile\VerificationRequest;
use Softavis\Turnstile\VerificationResult;
use Softavis\Turnstile\TurnstileVerifier;
use Softavis\Turnstile\RejectionReason;

final class DisabledTurnstileVerifier implements TurnstileVerifier
{
    public function verify(VerificationRequest $request): VerificationResult
    {
        return new VerificationResult(false, rejectionReasons: [RejectionReason::InvalidToken]);
    }
}
