<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Security;

use Softavis\Turnstile\Exception\TurnstileException;
use Softavis\Turnstile\TurnstileVerifier;
use Softavis\Turnstile\VerificationRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TurnstilePassportListener
{
    /**
     * @param array<string, mixed> $login
     * @param list<string>|null $expectedHostnames
     */
    public function __construct(
        private readonly TurnstileVerifier $verifier,
        private readonly RequestStack $requestStack,
        private readonly ?TranslatorInterface $translator,
        private readonly bool $enabled,
        private readonly array $login,
        private readonly ?array $expectedHostnames,
        private readonly bool $forwardClientIp,
    ) {
    }

    public function checkPassport(CheckPassportEvent $event): void
    {
        if (!$this->enabled) {
            $disabledBadge = $event->getPassport()->getBadge(TurnstileBadge::class);
            if ($disabledBadge instanceof TurnstileBadge) {
                $disabledBadge->markResolved();
            }

            return;
        }

        $passport = $event->getPassport();
        $badge = $passport->hasBadge(TurnstileBadge::class)
            ? $passport->getBadge(TurnstileBadge::class)
            : null;
        $automatic = $this->login['enabled']
            && $event->getAuthenticator() instanceof AbstractLoginFormAuthenticator
            && $passport->hasBadge(PasswordCredentials::class)
            && $this->isInConfiguredFirewall($this->requestStack->getCurrentRequest());

        if ($badge === null && !$automatic) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $response = $badge?->response() ?? $this->getSubmittedResponse($request);
        $action = $badge?->action() ?? $this->login['action'];

        if (!is_string($response) || trim($response) === '' || !is_string($action) || $action === '') {
            $this->reject();
        }

        try {
            $result = $this->verifier->verify(new VerificationRequest(
                response: $response,
                remoteIp: $this->forwardClientIp ? $request?->getClientIp() : null,
                expectedAction: $action,
                expectedHostnames: $this->expectedHostnames,
            ));
        } catch (TurnstileException|\InvalidArgumentException) {
            $this->reject();
        }

        if (!$result->isAccepted()) {
            $this->reject();
        }

        $badge?->markResolved();
    }

    private function getSubmittedResponse(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $value = $request->request->all()[$this->login['token_field']] ?? null;

        return is_string($value) ? $value : null;
    }

    private function isInConfiguredFirewall(?Request $request): bool
    {
        $firewalls = $this->login['firewalls'];
        if ($firewalls === []) {
            return true;
        }
        if ($request === null) {
            return false;
        }

        $firewall = $request->attributes->get('_firewall');
        if (!is_string($firewall)) {
            $context = $request->attributes->get('_firewall_context');
            if (is_string($context) && str_starts_with($context, 'security.firewall.map.context.')) {
                $firewall = substr($context, strlen('security.firewall.map.context.'));
            }
        }

        return is_string($firewall) && in_array($firewall, $firewalls, true);
    }

    private function reject(): never
    {
        throw new CustomUserMessageAuthenticationException(
            $this->translator?->trans('softavis_turnstile.authentication_failed', [], 'validators')
                ?? 'Unable to verify this login. Please try again.',
        );
    }
}
