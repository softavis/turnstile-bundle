<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
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
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->set(HttpFactory::class);
    $services->alias(RequestFactoryInterface::class, HttpFactory::class);
    $services->alias(ResponseFactoryInterface::class, HttpFactory::class);
    $services->alias(StreamFactoryInterface::class, HttpFactory::class);

    $services->set(HttpClient::class)
        ->factory([HttpClient::class, 'create'])
        ->args([['timeout' => 5.0, 'max_duration' => 10.0]]);
    $services->alias(HttpClientInterface::class, HttpClient::class);

    $services->set(Psr18Client::class)
        ->args([
            service(HttpClientInterface::class),
            service(\Psr\Http\Message\ResponseFactoryInterface::class),
            service(StreamFactoryInterface::class),
        ]);
    $services->alias(ClientInterface::class, Psr18Client::class);

    $services->set(HttpTurnstileVerifier::class)
        ->args([
            '%softavis_turnstile.secret%',
            service(ClientInterface::class),
            service(RequestFactoryInterface::class),
            service(StreamFactoryInterface::class),
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
        ])
        ->tag('form.type');

    $services->set(WidgetRenderer::class)
        ->args([
            '%softavis_turnstile.enabled%',
            '%softavis_turnstile.site_key%',
            '%softavis_turnstile.widget%',
        ]);
    $services->set(TurnstileExtension::class)
        ->args([service(WidgetRenderer::class)])
        ->tag('twig.extension');
};
