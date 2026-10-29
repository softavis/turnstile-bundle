<?php

declare(strict_types=1);

// Renders the bundle's real Twig templates for the browser tests. Prints JSON.

use Softavis\TurnstileBundle\Twig\TurnstileExtension;
use Softavis\TurnstileBundle\Twig\WidgetRenderer;
use Symfony\Bridge\Twig\Extension\AssetExtension;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$loader = new FilesystemLoader(dirname(__DIR__, 2).'/templates');
$loader->addPath(dirname(__DIR__, 2).'/templates', 'SoftavisTurnstile');
$twig = new Environment($loader, ['autoescape' => 'html']);
$twig->addExtension(new AssetExtension(new Packages(new PathPackage('', new EmptyVersionStrategy()))));

$defaults = [
    'theme' => 'auto',
    'size' => 'normal',
    'language' => 'auto',
    'appearance' => 'always',
    'default_action' => null,
];
$twig->addExtension(new TurnstileExtension(new WidgetRenderer(true, 'test-site-key', $defaults, $twig, null, true)));

$formTheme = $twig->createTemplate(
    '{% use "@SoftavisTurnstile/form/turnstile_widget.html.twig" %}{{ block("turnstile_widget") }}',
);
$form = static fn (string $id): string => $formTheme->render([
    'id' => $id,
    'full_name' => 'form['.$id.']',
    'value' => '',
    'enabled' => true,
    'site_key' => 'test-site-key',
    'theme' => 'auto',
    'size' => 'normal',
    'language' => 'auto',
    'appearance' => 'always',
    'turnstile_action' => null,
    'asset_package' => null,
    'turbo_enabled' => true,
    'attr' => [],
]);
$helper = $twig->createTemplate('{{ turnstile_widget(name) }}');

echo json_encode([
    'form' => [$form('token_one'), $form('token_two')],
    'helper' => [$helper->render(['name' => 'helper_one']), $helper->render(['name' => 'helper_two'])],
], JSON_THROW_ON_ERROR);
