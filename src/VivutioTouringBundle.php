<?php

declare(strict_types=1);

/*
 * This file is part of the vivutio touring module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vivutio\Touring;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Touring: tours written day by day through the core's destinations, where
 * each night is spent, and what the parks charge a party, from the fees the
 * organization entered in the core.
 */
final class VivutioTouringBundle extends AbstractBundle
{
    /** Configuration lives under "touring:". */
    protected string $extensionAlias = 'touring';

    /**
     * Its entities' mapping and its migrations, prepended so the installation
     * keeps the last word; each guarded.
     *
     * @see https://symfony.com/doc/current/bundles/prepend_extension.html
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'Touring' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Vivutio\\Touring\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ], prepend: true);
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            $container->extension('doctrine_migrations', [
                'migrations_paths' => [
                    'Vivutio\\Touring\\Migrations' => \dirname(__DIR__).'/migrations',
                ],
            ], prepend: true);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
    }
}
