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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Vivutio\Bundle\IdentityBundle\Service\PlaceDirectoryService;
use Vivutio\Bundle\PlaceBundle\Repository\DestinationRepository;
use Vivutio\Bundle\PlaceBundle\Service\DestinationFeeService;
use Vivutio\Bundle\PlaceBundle\Service\NightCostService;
use Vivutio\Contracts\Access\ConcernSourceInterface;
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Contracts\Shell\MenuSourceInterface;
use Vivutio\Touring\Access\TouringConcerns;
use Vivutio\Touring\Controller\SeasonController;
use Vivutio\Touring\Controller\TourBookingController;
use Vivutio\Touring\Controller\TourCancellationController;
use Vivutio\Touring\Controller\TourController;
use Vivutio\Touring\Controller\TourCostController;
use Vivutio\Touring\Controller\TourDepartureController;
use Vivutio\Touring\Repository\TourBookingRepository;
use Vivutio\Touring\Repository\TourDayRepository;
use Vivutio\Touring\Repository\TourDepartureRepository;
use Vivutio\Touring\Repository\TourRateRepository;
use Vivutio\Touring\Repository\TourRepository;
use Vivutio\Touring\Repository\TourSeasonRepository;
use Vivutio\Touring\Repository\TourTermsRepository;
use Vivutio\Touring\Service\ParkFeeService;
use Vivutio\Touring\Service\TourBookingService;
use Vivutio\Touring\Service\TourCancellationService;
use Vivutio\Touring\Service\TourCostService;
use Vivutio\Touring\Service\TourDepartureService;
use Vivutio\Touring\Service\TourPriceService;
use Vivutio\Touring\Service\TourSeasonService;
use Vivutio\Touring\Service\TourService;
use Vivutio\Touring\Shell\TouringMenu;

/*
 * Every service is defined explicitly, with an id prefixed by the bundle's
 * alias; nothing is autowired or autoconfigured, so a tag is applied by hand.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html#services
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('touring.access.concerns', TouringConcerns::class)
        ->tag(ConcernSourceInterface::TAG);
    $services->set('touring.menu', TouringMenu::class)
        ->tag(MenuSourceInterface::TAG);

    $services->set(TourRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(TourDayRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(TourSeasonRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(TourRateRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(TourBookingRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(TourDepartureRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(TourTermsRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set('touring.cancellation', TourCancellationService::class)
        ->args([service('doctrine.orm.entity_manager'), service(TourTermsRepository::class)]);

    $services->set('touring.departures', TourDepartureService::class)
        ->args([service('doctrine.orm.entity_manager'), service('clock'), service(TourDepartureRepository::class), service(TourBookingRepository::class), service('touring.prices')]);

    $services->set('touring.seasons', TourSeasonService::class)
        ->args([service('doctrine.orm.entity_manager'), service(TourSeasonRepository::class)]);
    $services->set('touring.prices', TourPriceService::class)
        ->args([service('doctrine.orm.entity_manager'), service(TourRateRepository::class), service('touring.seasons')]);

    $services->set('touring.tours', TourService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(TourRepository::class),
            service(DestinationRepository::class),
            service(PlaceDirectoryService::class),
            service(PartnerDirectoryInterface::class),
            service(DestinationFeeService::class),
        ]);

    $services->set('touring.park_fees', ParkFeeService::class)
        ->args([service(DestinationRepository::class), service(DestinationFeeService::class)]);

    $services->set('touring.controller.tours', TourController::class)
        ->args([
            service('twig'),
            service('touring.tours'),
            service('touring.park_fees'),
            service('touring.prices'),
            service('touring.seasons'),
            service('touring.departures'),
            service('touring.cancellation'),
            service(TourRepository::class),
            service(DestinationRepository::class),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public();
    $services->alias(TourController::class, 'touring.controller.tours')->public();

    $services->set('touring.controller.seasons', SeasonController::class)
        ->args([service('twig'), service('touring.seasons'), service('security.csrf.token_manager'), service('router')])
        ->public();
    $services->alias(SeasonController::class, 'touring.controller.seasons')->public();

    $services->set('touring.bookings', TourBookingService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('clock'),
            service(TourBookingRepository::class),
            service(TourRepository::class),
            service('touring.prices'),
            service('touring.seasons'),
            service(PartnerDirectoryInterface::class),
            service(TourDepartureRepository::class),
            service('touring.departures'),
            service('touring.cancellation'),
        ]);
    $services->set('touring.controller.bookings', TourBookingController::class)
        ->args([service('twig'), service('touring.bookings'), service('touring.tours'), service('touring.departures'), service('clock'), service('security.csrf.token_manager'), service('router')])
        ->public();
    $services->alias(TourBookingController::class, 'touring.controller.bookings')->public();

    $services->set('touring.costs', TourCostService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('clock'),
            service(NightCostService::class),
            service('touring.park_fees'),
            service('touring.seasons'),
            service('touring.prices'),
            service('touring.tours'),
            service(TourRateRepository::class),
        ]);
    $services->set('touring.controller.costs', TourCostController::class)
        ->args([service('twig'), service('touring.costs'), service('security.csrf.token_manager'), service('router')])
        ->public();
    $services->alias(TourCostController::class, 'touring.controller.costs')->public();

    $services->set('touring.controller.departures', TourDepartureController::class)
        ->args([service('twig'), service('touring.departures'), service('touring.tours'), service('security.csrf.token_manager'), service('router')])
        ->public();
    $services->alias(TourDepartureController::class, 'touring.controller.departures')->public();

    $services->set('touring.controller.cancellation', TourCancellationController::class)
        ->args([service('twig'), service('touring.cancellation'), service('security.authorization_checker'), service('security.csrf.token_manager'), service('router')])
        ->public();
    $services->alias(TourCancellationController::class, 'touring.controller.cancellation')->public();
};
