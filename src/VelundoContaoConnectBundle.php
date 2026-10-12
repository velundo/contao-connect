<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Bundle velundo/contao-connect. Moderne Verzeichnisstruktur (AbstractBundle): config/, contao/dca/, src/.
 * Einstellungen in config/config.yaml unter velundo_contao_connect (max_text_kb, rate_limit_per_minute, require_https).
 */
final class VelundoContaoConnectBundle extends AbstractBundle
{
    protected string $extensionAlias = 'velundo_contao_connect';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->integerNode('max_text_kb')->defaultValue(200)->min(1)->max(4096)
                    ->info('Höchstgröße von text und teaser in KB; größere Inhalte → 422 text_too_large')->end()
                ->integerNode('rate_limit_per_minute')->defaultValue(120)->min(1)->max(10000)
                    ->info('Anfragen je Minute und Token; darüber 429 mit Retry-After')->end()
                ->booleanNode('require_https')->defaultTrue()
                    ->info('Anfragen über http → 403 https_required (in APP_ENV=dev immer erlaubt)')->end()
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()->set('velundo_contao_connect.settings', $config);
        $container->import('../config/services.yaml');
    }
}
