<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests\Validator;

use PHPUnit\Framework\TestCase;
use Softavis\Turnstile\Exception\TransportException;
use Softavis\Turnstile\RejectionReason;
use Softavis\Turnstile\VerificationResult;
use Softavis\TurnstileBundle\Tests\StubVerifier;
use Softavis\TurnstileBundle\Validator\Constraints\Turnstile;
use Softavis\TurnstileBundle\Validator\Constraints\TurnstileValidator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidatorFactoryInterface;
use Symfony\Component\Validator\Validation;

final class TurnstileValidatorTest extends TestCase
{
    public function testValidatesTheSuppliedValueAndForwardsPolicy(): void
    {
        $verifier = new StubVerifier();
        $validator = $this->createValidator($verifier);

        self::assertCount(0, $validator->validate('submitted-token', new Turnstile(
            expectedAction: 'signup',
            expectedHostnames: ['example.test'],
        )));
        self::assertCount(1, $verifier->requests);
        self::assertSame('submitted-token', $verifier->requests[0]->response);
        self::assertSame('signup', $verifier->requests[0]->expectedAction);
        self::assertSame(['example.test'], $verifier->requests[0]->expectedHostnames);
    }

    public function testMissingOrRejectedValueIsInvalidWithoutReverification(): void
    {
        $verifier = new StubVerifier();
        $verifier->response = new VerificationResult(false, rejectionReasons: [RejectionReason::InvalidToken]);
        $validator = $this->createValidator($verifier);

        self::assertCount(1, $validator->validate('bad-token', new Turnstile()));
        self::assertCount(1, $verifier->requests);
        self::assertCount(1, $validator->validate('', new Turnstile()));
        self::assertCount(1, $verifier->requests);
    }

    public function testOperationalFailureUsesUnavailableMessage(): void
    {
        $verifier = new StubVerifier();
        $verifier->response = new TransportException(new \RuntimeException('transport details'));
        $validator = $this->createValidator($verifier);
        $violations = $validator->validate('token', new Turnstile());

        self::assertCount(1, $violations);
        self::assertSame('softavis_turnstile.unavailable', $violations[0]->getMessageTemplate());
    }

    public function testForwardsOnlySymfonyResolvedTrustedClientIp(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
        $request = Request::create('/');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('X-Forwarded-For', '198.51.100.25');
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $verifier = new StubVerifier();
        $validator = $this->createValidator($verifier, true, $requestStack);

        try {
            self::assertCount(0, $validator->validate('token', new Turnstile()));
            self::assertSame('198.51.100.25', $verifier->requests[0]->remoteIp);
        } finally {
            Request::setTrustedProxies([], 0);
        }
    }

    public function testDisabledModeDoesNotCallVerifier(): void
    {
        $verifier = new StubVerifier();
        $factory = new class ($verifier) implements ConstraintValidatorFactoryInterface {
            public function __construct(private readonly StubVerifier $verifier)
            {
            }

            public function getInstance(Constraint $constraint): \Symfony\Component\Validator\ConstraintValidatorInterface
            {
                return new TurnstileValidator($this->verifier, new RequestStack(), false, false, null);
            }
        };
        $validator = Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory($factory)
            ->getValidator();

        self::assertCount(0, $validator->validate('', new Turnstile()));
        self::assertCount(0, $verifier->requests);
    }

    private function createValidator(
        StubVerifier $verifier,
        bool $forwardClientIp = false,
        ?RequestStack $requestStack = null,
    ): \Symfony\Component\Validator\Validator\ValidatorInterface {
        $factory = new class ($verifier, $forwardClientIp, $requestStack ?? new RequestStack()) implements ConstraintValidatorFactoryInterface {
            public function __construct(
                private readonly StubVerifier $verifier,
                private readonly bool $forwardClientIp,
                private readonly RequestStack $requestStack,
            ) {
            }

            public function getInstance(Constraint $constraint): \Symfony\Component\Validator\ConstraintValidatorInterface
            {
                return new TurnstileValidator($this->verifier, $this->requestStack, true, $this->forwardClientIp, null);
            }
        };

        return Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory($factory)
            ->getValidator();
    }
}
