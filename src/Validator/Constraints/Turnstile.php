<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Turnstile extends Constraint
{
    public string $message = 'softavis_turnstile.invalid';
    public string $unavailableMessage = 'softavis_turnstile.unavailable';

    /**
     * @param list<string>|null $expectedHostnames
     */
    public function __construct(
        public ?string $expectedAction = null,
        public ?array $expectedHostnames = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(groups: $groups, payload: $payload);
    }

    public function validatedBy(): string
    {
        return TurnstileValidator::class;
    }
}
