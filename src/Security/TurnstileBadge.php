<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Security;

use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

final class TurnstileBadge implements BadgeInterface
{
    private bool $resolved = false;

    public function __construct(
        private readonly string $response,
        private readonly string $action,
    ) {
        if (trim($action) === '') {
            throw new \InvalidArgumentException('The Turnstile action must not be empty.');
        }
    }

    public function response(): string
    {
        return $this->response;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    public function markResolved(): void
    {
        $this->resolved = true;
    }
}
