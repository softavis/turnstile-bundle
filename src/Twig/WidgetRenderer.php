<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Twig;

use InvalidArgumentException;
use Symfony\Component\OptionsResolver\OptionsResolver;

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
        $siteKey = (string) ($options['site_key'] ?? '');
        $fieldName = $this->escape($name);

        if (!$this->enabled) {
            return sprintf('<input type="hidden" name="%s" id="%s" value="">', $fieldName, $id);
        }

        $attributes = [
            'data-softavis-turnstile' => 'true',
            'data-sitekey' => $siteKey,
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

        $data = '';
        foreach ($attributes as $key => $value) {
            $data .= sprintf(' %s="%s"', $key, $this->escape($value));
        }

        return sprintf(
            '<input type="hidden" name="%s" id="%s" value=""><div id="%s-widget"%s></div><script src="/bundles/softavisturnstile/turnstile.js" defer></script>',
            $fieldName,
            $id,
            $id,
            $data,
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
