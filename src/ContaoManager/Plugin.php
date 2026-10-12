<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Contao\NewsBundle\ContaoNewsBundle;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;
use Velundo\ContaoConnect\VelundoContaoConnectBundle;

/**
 * Contao Manager Plugin: registriert das Bundle (nach Core und – falls installiert – News) und die Route /_velundo/.
 */
final class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        $loadAfter = [ContaoCoreBundle::class];
        if (class_exists(ContaoNewsBundle::class)) {
            $loadAfter[] = ContaoNewsBundle::class;
        }
        return [BundleConfig::create(VelundoContaoConnectBundle::class)->setLoadAfter($loadAfter)];
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
    {
        $file = __DIR__ . '/../../config/routes.yaml';
        $loader = $resolver->resolve($file);
        return $loader === false ? null : $loader->load($file);
    }
}
