<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Validator\Constraints;

use Softavis\Turnstile\Exception\TurnstileException;
use Softavis\Turnstile\TurnstileVerifier;
use Softavis\Turnstile\VerificationRequest;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class TurnstileValidator extends ConstraintValidator
{
    /**
     * @param list<string>|null $expectedHostnames
     */
    public function __construct(
        private readonly TurnstileVerifier $verifier,
        private readonly RequestStack $requestStack,
        private readonly bool $enabled,
        private readonly bool $forwardClientIp,
        private readonly ?array $expectedHostnames,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Turnstile) {
            throw new UnexpectedTypeException($constraint, Turnstile::class);
        }

        if (!$this->enabled) {
            return;
        }

        if (!is_string($value) || trim($value) === '') {
            $this->context->buildViolation($constraint->message)->addViolation();

            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $remoteIp = $this->forwardClientIp ? $request?->getClientIp() : null;

        try {
            $result = $this->verifier->verify(new VerificationRequest(
                response: $value,
                remoteIp: $remoteIp,
                expectedAction: $constraint->expectedAction,
                expectedHostnames: $constraint->expectedHostnames ?? $this->expectedHostnames,
            ));
        } catch (TurnstileException|\InvalidArgumentException) {
            $this->context->buildViolation($constraint->unavailableMessage)->addViolation();

            return;
        }

        if (!$result->isAccepted()) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
