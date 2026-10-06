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

namespace Vivutio\Touring\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Vivutio\Bundle\IdentityBundle\Test\AuthorityTestCase;
use Vivutio\Bundle\IdentityBundle\Test\Probe;
use Vivutio\Touring\Controller\SeasonController;
use Vivutio\Touring\Controller\TourBookingController;
use Vivutio\Touring\Controller\TourController;
use Vivutio\Touring\Controller\TourCostController;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Entity\TourDay;
use Vivutio\Touring\Entity\TourSeason;
use Vivutio\Touring\Enum\SeasonToneEnum;
use Vivutio\Touring\Tests\Application\Kernel;

/**
 * The module held to the core's five proofs, through the base every module's
 * suite extends. Record a reviewed change to the table with:
 *
 *     VIVUTIO_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit --filter TouringAuthorityTest
 */
final class TouringAuthorityTest extends AuthorityTestCase
{
    private const string TOUR_UUID = '0199b1c0-0000-7000-8000-00000000a001';
    private const string DAY_UUID = '0199b1c0-0000-7000-8000-00000000a002';
    private const string TOUR = '/tours/'.self::TOUR_UUID;
    private const string SEASON_UUID = '0199b1c0-0000-7000-8000-00000000a003';
    private const string SEASON = '/tours/seasons/'.self::SEASON_UUID;
    private const string BOOKING_UUID = '0199b1c0-0000-7000-8000-00000000a004';
    private const string BOOKING = '/tours/bookings/'.self::BOOKING_UUID;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected static function probes(): array
    {
        $itinerary = ['tiers' => '', 'step' => 'save', 'days' => [['title' => 'Probed day', 'destinations' => ['tz-tarangire-national-park', '', ''], 'stays' => [''], 'nights' => '1', 'activities' => '', 'description' => '', 'distance_km' => '', 'drive_hours' => '']]];

        return [
            new Probe(TourController::REGISTER, 'GET', '/tours'),
            new Probe(TourController::SHOW, 'GET', self::TOUR),
            new Probe(TourController::SHOW, 'GET', self::TOUR.'?start=2026-11-01&adults=2&children=0&residency=non_resident'),
            new Probe(TourController::CONFIGURE, 'GET', self::TOUR.'/configure'),
            new Probe(TourController::CONFIGURE, 'POST', self::TOUR.'/configure', ['name' => 'Probed tour', 'summary' => '', 'group_min' => '1', 'group_max' => '6', 'included' => '', 'excluded' => ''], formAt: self::TOUR.'/configure'),
            new Probe(TourController::ITINERARY, 'GET', self::TOUR.'/itinerary'),
            new Probe(TourController::ITINERARY, 'POST', self::TOUR.'/itinerary', $itinerary, formAt: self::TOUR.'/itinerary'),
            new Probe(TourController::PRICES, 'GET', self::TOUR.'/prices'),
            new Probe(TourController::PRICES, 'POST', self::TOUR.'/prices', ['currency' => 'USD', 'brackets' => '1-6'], formAt: self::TOUR.'/prices'),
            new Probe(TourCostController::COSTS, 'GET', self::TOUR.'/costs'),
            new Probe(TourCostController::COSTS, 'POST', self::TOUR.'/costs', ['margin' => '20', 'costs' => [['name' => 'Vehicle', 'per' => 'group', 'amount' => '900']]], formAt: self::TOUR.'/costs'),
            new Probe(TourCostController::TAKE, 'POST', self::TOUR.'/costs/take', ['tier' => '0'], formAt: self::TOUR.'/costs'),
            new Probe(SeasonController::SEASONS, 'GET', '/tours/seasons'),
            new Probe(SeasonController::CONFIGURE, 'GET', self::SEASON.'/configure'),
            new Probe(SeasonController::CONFIGURE, 'POST', self::SEASON.'/configure', ['name' => 'Probed season', 'tone' => '2', 'rest' => '1'], formAt: self::SEASON.'/configure'),
            new Probe(TourBookingController::REGISTER, 'GET', '/tours/bookings'),
            new Probe(TourBookingController::BOOKING, 'GET', self::BOOKING),
            new Probe(TourBookingController::NEW, 'GET', '/tours/bookings/new'),
            new Probe(TourBookingController::NEW, 'POST', '/tours/bookings/new', ['step' => 'price'], formAt: '/tours/bookings/new'),
            // Confirmed by the first allowed; the next finds it confirmed already.
            new Probe(TourBookingController::CONFIRM, 'POST', self::BOOKING.'/confirm', formAt: self::BOOKING),
            // Cancelled by the first allowed; the next finds it cancelled already.
            new Probe(TourBookingController::CANCEL, 'POST', self::BOOKING.'/cancel', ['reason' => 'Probed'], formAt: self::BOOKING),
            new Probe(TourController::OPEN, 'POST', self::TOUR.'/open', formAt: self::TOUR.'/configure'),
            new Probe(TourController::ARCHIVE, 'POST', self::TOUR.'/archive', formAt: self::TOUR.'/configure'),
            // Sent by each kind of person in turn, so the second allowed finds the name taken.
            new Probe(SeasonController::ADD, 'POST', '/tours/seasons', ['name' => 'Added by a probe', 'tone' => '1'], formAt: '/tours/seasons'),
            // Removed by the first allowed, and not found by the next.
            new Probe(SeasonController::REMOVE, 'POST', self::SEASON.'/remove', formAt: self::SEASON.'/configure'),
            new Probe(TourController::ADD, 'POST', '/tours', ['name' => 'Added by a probe'], formAt: '/tours'),
        ];
    }

    protected static function packageDirectory(): string
    {
        return \dirname(__DIR__);
    }

    protected static function authorityTable(): string
    {
        return __DIR__.'/authority-table.md';
    }

    protected function seedSubjects(EntityManagerInterface $entityManager): void
    {
        $tour = (new Tour('Probed tour'))->setUuid(Uuid::fromString(self::TOUR_UUID));
        $entityManager->persist($tour);
        $day = (new TourDay($tour, 1))->setUuid(Uuid::fromString(self::DAY_UUID))->setTitle('Probed day');
        $tour->getDays()->add($day);
        $entityManager->persist($day);
        $entityManager->persist((new TourSeason('Probed season', SeasonToneEnum::Busy))->setUuid(Uuid::fromString(self::SEASON_UUID)));
        $entityManager->persist((new TourBooking($tour, 'PT-0001', 'Probed party', new \DateTimeImmutable('2027-08-02'), new \DateTimeImmutable('2026-10-06 09:00')))
            ->setUuid(Uuid::fromString(self::BOOKING_UUID))
            ->setParty(2, 0, 'non_resident')
            ->setPrice(0, 'The tour', 'Probed season', '2 people', 'USD', 100000, 200000, 200000)
            ->setHeldUntil(new \DateTimeImmutable('2027-01-01')));
    }
}
