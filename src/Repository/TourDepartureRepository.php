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
use Vivutio\Touring\Entity\TourDeparture;
use Vivutio\Touring\Enum\DepartureStatusEnum;

/**
 * @extends ServiceEntityRepository<TourDeparture>
 */
class TourDepartureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TourDeparture::class);
    }

    /**
     * The departures of every tour leaving on the day given or later, not
     * cancelled, the soonest first.
     *
     * @return list<TourDeparture>
     */
    public function aheadOf(\DateTimeImmutable $today): array
    {
        /** @var list<TourDeparture> */
        return $this->createQueryBuilder('d')
            ->where('d.start >= :today')
            ->andWhere('d.status <> :cancelled')
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->setParameter('cancelled', DepartureStatusEnum::Cancelled)
            ->orderBy('d.start', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
