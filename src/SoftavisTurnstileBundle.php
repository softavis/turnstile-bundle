<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle;

use Softavis\Turnstile\HttpTurnstileVerifier;
use Softavis\Turnstile\TurnstileVerifier;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SoftavisTurnstileBundle extends AbstractBundle
{
    public function prependExtension(
        ContainerConfigurator $configurator,
        ContainerBuilder $container,
    ): void {
        $container->prependExtensionConfig('twig', [
            'form_themes' => ['@SoftavisTurnstile/form/turnstile_widget.html.twig'],
        ]);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition<null> $rootNode */
        $rootNode = $definition->rootNode();
        $rootNode
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('site_key')->defaultNull()->end()
                ->scalarNode('secret')->defaultNull()->end()
                ->arrayNode('expected_hostnames')
                    ->stringPrototype()->cannotBeEmpty()->end()
                    ->defaultNull()
                ->end()
                ->booleanNode('forward_client_ip')->defaultFalse()->end()
                ->stringNode('asset_package')->cannotBeEmpty()->defaultNull()->end()
                ->arrayNode('turbo')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('widget')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('theme')->values(['auto', 'light', 'dark'])->defaultValue('auto')->end()
                        ->enumNode('size')->values(['normal', 'compact', 'flexible'])->defaultValue('normal')->end()
                        ->stringNode('language')->cannotBeEmpty()->defaultValue('auto')->end()
                        ->enumNode('appearance')->values(['always', 'execute', 'interaction-only'])->defaultValue('always')->end()
                        ->stringNode('default_action')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('login')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->stringNode('action')->defaultNull()->end()
                        ->stringNode('token_field')->cannotBeEmpty()->defaultValue('cf-turnstile-response')->end()
                        ->arrayNode('firewalls')
                            ->stringPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('transport')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->stringNode('http_client_service')->cannotBeEmpty()->defaultNull()->end()
                        ->stringNode('psr18_client_service')->cannotBeEmpty()->defaultNull()->end()
                        ->stringNode('psr17_factory_service')->cannotBeEmpty()->defaultNull()->end()
                    ->end()
                ->end()
                ->stringNode('verifier_service')->defaultNull()->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => $config['enabled'] && (
                    !is_string($config['site_key']) || trim($config['site_key']) === ''
                    || !is_string($config['secret']) || trim($config['secret']) === ''
                ))
                ->thenInvalid('softavis_turnstile.site_key and softavis_turnstile.secret must be configured when verification is enabled.')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => $config['expected_hostnames'] === [])
                ->thenInvalid('softavis_turnstile.expected_hostnames must be a non-empty list when configured.')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => $config['widget']['default_action'] !== null && (
                    !is_string($config['widget']['default_action']) || trim($config['widget']['default_action']) === ''
                ))
                ->thenInvalid('softavis_turnstile.widget.default_action must not be blank when configured.')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => $config['login']['enabled'] && (
                    !is_string($config['login']['action']) || trim($config['login']['action']) === ''
                ))
                ->thenInvalid('softavis_turnstile.login.action is required when password-login protection is enabled.')
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        foreach ([
            'enabled',
            'site_key',
            'secret',
            'expected_hostnames',
            'forward_client_ip',
            'asset_package',
            'turbo',
            'widget',
            'login',
            'transport',
        ] as $key) {
            $builder->setParameter('softavis_turnstile.'.$key, $config[$key]);
        }
        $builder->setParameter('softavis_turnstile.turbo.enabled', $config['turbo']['enabled']);

        $loader = new PhpFileLoader($builder, new FileLocator(__DIR__.'/../config'));
        $loader->load('services.php');

        $httpClientService = $config['transport']['http_client_service'] ?? 'softavis_turnstile.http_client';
        $psr18ClientService = $config['transport']['psr18_client_service'] ?? 'softavis_turnstile.psr18_client';
        $psr17FactoryService = $config['transport']['psr17_factory_service'] ?? 'softavis_turnstile.psr17_factory';
        $builder->getDefinition('softavis_turnstile.psr18_client')->setArguments([
            new Reference($httpClientService),
            new Reference($psr17FactoryService),
            new Reference($psr17FactoryService),
        ]);
        $builder->getDefinition(HttpTurnstileVerifier::class)->setArguments([
            '%softavis_turnstile.secret%',
            new Reference($psr18ClientService),
            new Reference($psr17FactoryService),
            new Reference($psr17FactoryService),
        ]);

        if (is_string($config['verifier_service']) && $config['verifier_service'] !== '') {
            $builder->setAlias(TurnstileVerifier::class, $config['verifier_service']);
        } elseif ($config['enabled']) {
            $builder->setAlias(TurnstileVerifier::class, HttpTurnstileVerifier::class);
        } else {
            $builder->setAlias(TurnstileVerifier::class, Service\DisabledTurnstileVerifier::class);
        }

        if (class_exists(\Symfony\Component\Security\Http\Event\CheckPassportEvent::class)) {
            $builder->register(Security\TurnstilePassportListener::class)
                ->setArguments([
                    new Reference(TurnstileVerifier::class),
                    new Reference('request_stack'),
                    new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    '%softavis_turnstile.enabled%',
                    '%softavis_turnstile.login%',
                    '%softavis_turnstile.expected_hostnames%',
                    '%softavis_turnstile.forward_client_ip%',
                ])
                ->addTag('kernel.event_listener', [
                    'event' => \Symfony\Component\Security\Http\Event\CheckPassportEvent::class,
                    'method' => 'checkPassport',
                    'priority' => 384,
                ]);
        }
    }
}
