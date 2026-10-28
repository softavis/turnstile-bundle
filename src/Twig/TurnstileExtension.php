<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TurnstileExtension extends AbstractExtension
{
    public function __construct(private readonly WidgetRenderer $renderer)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('turnstile_widget', [$this->renderer, 'render'], ['is_safe' => ['html']]),
        ];
    }
}
