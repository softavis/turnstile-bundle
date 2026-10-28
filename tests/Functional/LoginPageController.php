<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class LoginPageController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokens,
    ) {
    }

    public function __invoke(): Response
    {
        $token = htmlspecialchars($this->csrfTokens->getToken('authenticate')->getValue(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return new Response(sprintf('<form><input name="_csrf_token" value="%s"></form>', $token));
    }
}
