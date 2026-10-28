<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Softavis\TurnstileBundle\Twig\TurnstileExtension;
use Softavis\TurnstileBundle\Twig\WidgetRenderer;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;

final class WidgetRendererTest extends TestCase
{
    public function testEscapesValuesAndCreatesUniqueWidgets(): void
    {
        $renderer = new WidgetRenderer(true, 'site"&key', [
            'theme' => 'auto',
            'size' => 'normal',
            'language' => 'auto',
            'appearance' => 'always',
            'default_action' => null,
        ]);
        $first = $renderer->render('login[token]', ['action' => 'log"<in>']);
        $second = $renderer->render('other');

        self::assertStringContainsString('name="login[token]"', $first);
        self::assertStringContainsString('data-sitekey="site&quot;&amp;key"', $first);
        self::assertStringContainsString('data-action="log&quot;&lt;in&gt;"', $first);
        self::assertSame(2, substr_count($first.$second, 'src="/bundles/softavisturnstile/turnstile.js"'));
        self::assertStringContainsString('id="softavis-turnstile-0"', $first);
        self::assertStringContainsString('id="softavis-turnstile-1"', $second);
        self::assertStringNotContainsString('secret', $first);
    }

    public function testDisabledModeRendersNoActiveWidgetOrLoader(): void
    {
        $renderer = new WidgetRenderer(false, null, [
            'theme' => 'auto',
            'size' => 'normal',
            'language' => 'auto',
            'appearance' => 'always',
            'default_action' => null,
        ]);
        $html = $renderer->render('captcha');

        self::assertStringContainsString('type="hidden"', $html);
        self::assertStringNotContainsString('data-softavis-turnstile', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testTwigFunctionMarksEscapedWidgetAsSafeMarkup(): void
    {
        $renderer = new WidgetRenderer(true, 'public-key', [
            'theme' => 'auto',
            'size' => 'normal',
            'language' => 'auto',
            'appearance' => 'always',
            'default_action' => null,
        ]);
        $twig = new Environment(new ArrayLoader([
            'widget' => '{{ turnstile_widget(name, {action: action}) }}',
        ]));
        $twig->addExtension(new TurnstileExtension($renderer));

        $html = $twig->render('widget', ['name' => 'login[token]', 'action' => 'log"<in>']);

        self::assertStringContainsString('name="login[token]"', $html);
        self::assertStringContainsString('data-action="log&quot;&lt;in&gt;"', $html);
        self::assertStringNotContainsString('&amp;quot;', $html);
    }

    public function testFormThemeRendersEscapedNestedInputAndBlankToken(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2).'/templates');
        $loader->addPath(dirname(__DIR__, 2).'/templates', 'SoftavisTurnstile');
        $twig = new Environment($loader, ['autoescape' => 'html']);
        $template = $twig->createTemplate(
            '{% use "@SoftavisTurnstile/form/turnstile_widget.html.twig" %}{{ block("turnstile_widget") }}',
        );

        $html = $template->render([
            'id' => 'registration_captcha',
            'full_name' => 'registration[captcha]',
            'value' => 'used-token',
            'enabled' => true,
            'site_key' => 'public"&key',
            'theme' => 'auto',
            'size' => 'normal',
            'language' => 'auto',
            'appearance' => 'always',
            'turnstile_action' => 'signup',
            'attr' => ['title' => 'widget"&title'],
        ]);

        self::assertStringContainsString('name="registration[captcha]"', $html);
        self::assertStringContainsString('data-sitekey="public&quot;&amp;key"', $html);
        self::assertStringContainsString('title="widget&quot;&amp;title"', $html);
        self::assertStringContainsString('value=""', $html);
        self::assertStringNotContainsString('used-token', $html);
        self::assertSame(1, substr_count($html, 'src="/bundles/softavisturnstile/turnstile.js"'));
    }
}
