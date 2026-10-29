<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Softavis\Turnstile\HttpTurnstileVerifier;
use Softavis\Turnstile\TurnstileVerifier;
use Softavis\TurnstileBundle\Form\TurnstileType;
use Softavis\TurnstileBundle\Service\DisabledTurnstileVerifier;
use Softavis\TurnstileBundle\Twig\TurnstileExtension;
use Softavis\TurnstileBundle\Twig\WidgetRenderer;
use Softavis\TurnstileBundle\Validator\Constraints\TurnstileValidator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Twig\Environment;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->set('softavis_turnstile.psr17_factory', HttpFactory::class);

    $services->set('softavis_turnstile.http_client', HttpClient::class)
        ->factory([HttpClient::class, 'create'])
        ->args([['timeout' => 5.0, 'max_duration' => 10.0]]);

    $services->set('softavis_turnstile.psr18_client', Psr18Client::class)
        ->args([
            service('softavis_turnstile.http_client'),
            service('softavis_turnstile.psr17_factory'),
            service('softavis_turnstile.psr17_factory'),
        ]);

    $services->set(HttpTurnstileVerifier::class)
        ->args([
            '%softavis_turnstile.secret%',
            service('softavis_turnstile.psr18_client'),
            service('softavis_turnstile.psr17_factory'),
            service('softavis_turnstile.psr17_factory'),
        ]);
    $services->set(DisabledTurnstileVerifier::class);

    $services->set(TurnstileValidator::class)
        ->args([
            service(TurnstileVerifier::class),
            service('request_stack'),
            '%softavis_turnstile.enabled%',
            '%softavis_turnstile.forward_client_ip%',
            '%softavis_turnstile.expected_hostnames%',
        ])
        ->tag('validator.constraint_validator');

    $services->set(TurnstileType::class)
        ->args([
            '%softavis_turnstile.enabled%',
            '%softavis_turnstile.site_key%',
            '%softavis_turnstile.widget%',
            '%softavis_turnstile.asset_package%',
            '%softavis_turnstile.turbo.enabled%',
        ])
        ->tag('form.type');

    $services->set(WidgetRenderer::class)
        ->args([
            '%softavis_turnstile.enabled%',
            '%softavis_turnstile.site_key%',
            '%softavis_turnstile.widget%',
            service('twig'),
            '%softavis_turnstile.asset_package%',
            '%softavis_turnstile.turbo.enabled%',
        ]);
    $services->set(TurnstileExtension::class)
        ->args([service(WidgetRenderer::class)])
        ->tag('twig.extension');
};
