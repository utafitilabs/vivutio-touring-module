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
use Vivutio\Touring\Controller\TourController;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourDay;
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
    private const string DAY = self::TOUR.'/days/'.self::DAY_UUID;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected static function probes(): array
    {
        $day = ['title' => 'Probed day', 'destinations' => ['tz-tarangire', '', ''], 'overnight' => '', 'description' => '', 'distance_km' => '', 'drive_hours' => ''];

        return [
            new Probe(TourController::REGISTER, 'GET', '/tours'),
            new Probe(TourController::SHOW, 'GET', self::TOUR),
            new Probe(TourController::SHOW, 'GET', self::TOUR.'?start=2026-11-01&adults=2&children=0&residency=non_resident'),
            new Probe(TourController::CONFIGURE, 'GET', self::TOUR.'/configure'),
            new Probe(TourController::CONFIGURE, 'POST', self::TOUR.'/configure', ['name' => 'Probed tour', 'summary' => '', 'group_min' => '1', 'group_max' => '6', 'included' => '', 'excluded' => ''], formAt: self::TOUR.'/configure'),
            new Probe(TourController::CONFIGURE_DAY, 'GET', self::DAY.'/configure'),
            new Probe(TourController::CONFIGURE_DAY, 'POST', self::DAY.'/configure', $day, formAt: self::DAY.'/configure'),
            new Probe(TourController::ADD_DAY, 'POST', self::TOUR.'/days', $day, formAt: self::TOUR),
            new Probe(TourController::OPEN, 'POST', self::TOUR.'/open', formAt: self::TOUR.'/configure'),
            new Probe(TourController::ARCHIVE, 'POST', self::TOUR.'/archive', formAt: self::TOUR.'/configure'),
            // Removed by the first allowed, and not found by the next.
            new Probe(TourController::REMOVE_DAY, 'POST', self::DAY.'/remove', formAt: self::TOUR),
            // Sent by each kind of person in turn, so the second allowed finds the name taken.
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
    }
}
