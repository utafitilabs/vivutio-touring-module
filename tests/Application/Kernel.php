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

namespace Vivutio\Touring\Tests\Application;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Vivutio\Bundle\IdentityBundle\Controller\SecurityController;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Contracts\Place\PlaceSourceInterface;
use Vivutio\Contracts\Stay\NightCostSourceInterface;
use Vivutio\Touring\Tests\Application\StandIn\StandInNightCosts;
use Vivutio\Touring\Tests\Application\StandIn\StandInPlaces;

/**
 * An installation in miniature: the core's bundles and this module, the
 * firewall an installation writes, PostgreSQL, the module's routes mounted as
 * its recipe mounts them, and stand-ins for what other packages offer it.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    /**
     * A kernel without debug never rebuilds its cache when a file changes, so
     * it keeps a folder of its own, which the authority base empties first.
     */
    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->environment.($this->debug ? '' : '_without_debug');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@IdentityBundle/Controller/', 'attribute');
        $routes->import('@ShellBundle/Controller/', 'attribute');
        $routes->import(\dirname(__DIR__, 2).'/config/routes/touring.yaml');
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => true,
            'mailer' => ['dsn' => 'null://null'],
        ]);

        $container->extension('doctrine', [
            'dbal' => ['url' => '%env(VIVUTIO_TEST_DATABASE_URL)%'],
            'orm' => [
                'controller_resolver' => ['auto_mapping' => false],
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                'identity_generation_preferences' => [
                    PostgreSQLPlatform::class => 'identity',
                ],
            ],
        ]);

        // What an installation writes in its own security.yaml.
        $container->extension('security', [
            'password_hashers' => [
                PasswordAuthenticatedUserInterface::class => ['algorithm' => 'auto', 'cost' => 4, 'time_cost' => 3, 'memory_cost' => 10],
            ],
            'providers' => [
                'identity_user_provider' => ['entity' => ['class' => User::class]],
            ],
            'firewalls' => [
                'main' => [
                    'lazy' => true,
                    'provider' => 'identity_user_provider',
                    'user_checker' => 'identity.user_checker',
                    'form_login' => [
                        'login_path' => SecurityController::SIGN_IN,
                        'check_path' => SecurityController::SIGN_IN,
                        'enable_csrf' => true,
                        'default_target_path' => '/',
                    ],
                    'logout' => ['path' => SecurityController::SIGN_OUT, 'target' => SecurityController::SIGN_IN],
                ],
            ],
            'access_control' => [
                ['path' => '^/login', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/', 'roles' => 'ROLE_USER'],
            ],
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);

        // A place a package offers, played by a stand-in.
        $services->set('test.stand_in.places', StandInPlaces::class)->tag(PlaceSourceInterface::TAG);
        $services->set('test.stand_in.night_costs', StandInNightCosts::class)->tag(NightCostSourceInterface::TAG);
    }
}
