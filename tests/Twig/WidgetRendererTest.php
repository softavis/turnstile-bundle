<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Softavis\TurnstileBundle\Twig\TurnstileExtension;
use Softavis\TurnstileBundle\Twig\WidgetRenderer;
use Symfony\Bridge\Twig\Extension\AssetExtension;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\Packages;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
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
        ], $this->createTwig('/application'));
        $first = $renderer->render('login[token]', ['action' => 'log"<in>']);
        $second = $renderer->render('other');

        self::assertStringContainsString('name="login[token]"', $first);
        self::assertStringContainsString('data-sitekey="site&quot;&amp;key"', $first);
        self::assertStringContainsString('data-action="log&quot;&lt;in&gt;"', $first);
        self::assertSame(2, substr_count($first.$second, 'src="/application/bundles/softavisturnstile/turnstile.js"'));
        self::assertStringNotContainsString('turnstile-turbo.js', $first.$second);
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
        ], $this->createTwig('/application'));
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
        ], $this->createTwig('/application', [
            'widget' => '{{ turnstile_widget(name, {action: action}) }}',
        ]));
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
        $twig = $this->createTwig('/application');
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
            'asset_package' => null,
            'attr' => ['title' => 'widget"&title'],
        ]);

        self::assertStringContainsString('name="registration[captcha]"', $html);
        self::assertStringContainsString('data-sitekey="public&quot;&amp;key"', $html);
        self::assertStringContainsString('title="widget&quot;&amp;title"', $html);
        self::assertStringContainsString('value=""', $html);
        self::assertStringNotContainsString('used-token', $html);
        self::assertSame(1, substr_count($html, 'src="/application/bundles/softavisturnstile/turnstile.js"'));
        self::assertStringNotContainsString('turnstile-turbo.js', $html);
    }

    public function testFormThemeLoadsTurboAdapterOnlyWhenEnabledAndAfterRuntime(): void
    {
        $twig = $this->createTwig('/application');
        $template = $twig->createTemplate(
            '{% use "@SoftavisTurnstile/form/turnstile_widget.html.twig" %}{{ block("turnstile_widget") }}',
        );

        $html = $template->render([
            'id' => 'captcha',
            'full_name' => 'captcha',
            'value' => '',
            'enabled' => true,
            'turbo_enabled' => true,
            'site_key' => 'public',
            'theme' => 'auto',
            'size' => 'normal',
            'language' => 'auto',
            'appearance' => 'always',
            'turnstile_action' => null,
            'asset_package' => null,
            'attr' => [],
        ]);

        self::assertStringContainsString('turnstile.js', $html);
        self::assertStringContainsString('turnstile-turbo.js', $html);
        self::assertLessThan(
            strpos($html, 'turnstile-turbo.js'),
            strpos($html, 'turnstile.js'),
        );
    }

    public function testStandaloneRendererUsesConfiguredNamedAssetPackage(): void
    {
        $renderer = new WidgetRenderer(true, 'public-key', [
            'theme' => 'auto',
            'size' => 'normal',
            'language' => 'auto',
            'appearance' => 'always',
            'default_action' => null,
        ], $this->createTwig('/application'), 'cdn');

        $html = $renderer->render('captcha');

        self::assertStringContainsString('src="/cdn-assets/bundles/softavisturnstile/turnstile.js"', $html);
    }

    public function testStandaloneRendererIncludesTurboAdapterFromConfiguredAssetPackage(): void
    {
        $renderer = new WidgetRenderer(true, 'public-key', [
            'theme' => 'auto',
            'size' => 'normal',
            'language' => 'auto',
            'appearance' => 'always',
            'default_action' => null,
        ], $this->createTwig('/application'), 'cdn', true);

        $html = $renderer->render('captcha');

        self::assertStringContainsString('src="/cdn-assets/bundles/softavisturnstile/turnstile.js"', $html);
        self::assertStringContainsString('src="/cdn-assets/bundles/softavisturnstile/turnstile-turbo.js"', $html);
        self::assertLessThan(
            strpos($html, 'turnstile-turbo.js'),
            strpos($html, 'turnstile.js'),
        );
    }

    /**
     * @param array<string, string> $inlineTemplates
     */
    private function createTwig(string $basePath, array $inlineTemplates = []): Environment
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2).'/templates');
        $loader->addPath(dirname(__DIR__, 2).'/templates', 'SoftavisTurnstile');
        $twig = new Environment(new ChainLoader([
            new ArrayLoader($inlineTemplates),
            $loader,
        ]), ['autoescape' => 'html']);
        $twig->addExtension(new AssetExtension(new Packages(
            new PathPackage($basePath, new \Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy()),
            ['cdn' => new PathPackage('/cdn-assets', new \Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy())],
        )));

        return $twig;
    }
}
