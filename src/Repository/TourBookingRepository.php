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

namespace Vivutio\Touring\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Enum\TourBookingStatusEnum;

/**
 * @extends ServiceEntityRepository<TourBooking>
 */
class TourBookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TourBooking::class);
    }

    /**
     * The bookings starting between two days, both counted, that are
     * confirmed, or held and not lapsed on the day given; the soonest first.
     *
     * @return list<TourBooking>
     */
    public function standingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): array
    {
        /** @var list<TourBooking> */
        return $this->createQueryBuilder('b')
            ->where('b.start >= :from')
            ->andWhere('b.start <= :to')
            ->andWhere('b.status = :confirmed OR (b.status = :held AND (b.heldUntil IS NULL OR b.heldUntil >= :today))')
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->setParameter('confirmed', TourBookingStatusEnum::Confirmed)
            ->setParameter('held', TourBookingStatusEnum::Provisional)
            ->orderBy('b.start', 'ASC')
            ->addOrderBy('b.reference', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The confirmed bookings starting between two days, both counted.
     *
     * @return list<TourBooking>
     */
    public function confirmedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<TourBooking> */
        return $this->createQueryBuilder('b')
            ->where('b.start >= :from')
            ->andWhere('b.start <= :to')
            ->andWhere('b.status = :confirmed')
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->setParameter('confirmed', TourBookingStatusEnum::Confirmed)
            ->orderBy('b.start', 'ASC')
            ->addOrderBy('b.reference', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The bookings held and not lapsed on the day given, the soonest to let go first.
     *
     * @return list<TourBooking>
     */
    public function heldOn(\DateTimeImmutable $today): array
    {
        /** @var list<TourBooking> */
        return $this->createQueryBuilder('b')
            ->where('b.status = :held')
            ->andWhere('b.heldUntil IS NULL OR b.heldUntil >= :today')
            ->setParameter('held', TourBookingStatusEnum::Provisional)
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->orderBy('b.heldUntil', 'ASC')
            ->addOrderBy('b.reference', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
