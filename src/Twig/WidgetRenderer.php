<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Twig;

use InvalidArgumentException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Twig\Environment;

final class WidgetRenderer
{
    private int $counter = 0;

    /**
     * @param array{theme: string, size: string, language: string, appearance: string, default_action: ?string} $defaults
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly ?string $siteKey,
        private readonly array $defaults,
        private readonly Environment $twig,
        private readonly ?string $assetPackage = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function render(string $name = 'cf-turnstile-response', array $options = []): string
    {
        if ($name === '') {
            throw new InvalidArgumentException('The Turnstile response field name must not be empty.');
        }

        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'site_key' => $this->siteKey,
            'theme' => $this->defaults['theme'],
            'size' => $this->defaults['size'],
            'language' => $this->defaults['language'],
            'appearance' => $this->defaults['appearance'],
            'action' => $this->defaults['default_action'],
        ]);
        $resolver->setAllowedTypes('site_key', ['null', 'string']);
        $resolver->setAllowedTypes('theme', 'string');
        $resolver->setAllowedTypes('size', 'string');
        $resolver->setAllowedTypes('language', 'string');
        $resolver->setAllowedTypes('appearance', 'string');
        $resolver->setAllowedTypes('action', ['null', 'string']);
        $resolver->setAllowedValues('theme', ['auto', 'light', 'dark']);
        $resolver->setAllowedValues('size', ['normal', 'compact', 'flexible']);
        $resolver->setAllowedValues('appearance', ['always', 'execute', 'interaction-only']);
        $resolver->setAllowedValues('language', static fn (string $language): bool => trim($language) !== '');
        $resolver->setAllowedValues('action', static fn (?string $action): bool => $action === null || trim($action) !== '');
        $options = $resolver->resolve($options);
        $id = 'softavis-turnstile-'.$this->counter++;
        $attributes = [
            'data-softavis-turnstile' => 'true',
            'data-sitekey' => (string) ($options['site_key'] ?? ''),
            'data-token-input' => $id,
            'data-theme' => $options['theme'],
            'data-size' => $options['size'],
            'data-language' => $options['language'],
            'data-appearance' => $options['appearance'],
        ];
        $action = $options['action'];
        if (is_string($action) && $action !== '') {
            $attributes['data-action'] = $action;
        }

        return $this->twig->render('@SoftavisTurnstile/widget.html.twig', [
            'enabled' => $this->enabled,
            'name' => $name,
            'id' => $id,
            'attributes' => $attributes,
            'asset_package' => $this->assetPackage,
        ]);
    }
}
