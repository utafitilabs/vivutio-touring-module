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

namespace Vivutio\Touring\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Entity\TourDeparture;
use Vivutio\Touring\Enum\DepartureStatusEnum;
use Vivutio\Touring\Enum\TourBookingStatusEnum;
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Repository\TourBookingRepository;
use Vivutio\Touring\Repository\TourDepartureRepository;

/**
 * A tour's departures: set days it leaves, sold by the seat in one tier. They
 * are added one, or a run of them a week or a fortnight apart; a seat's price
 * is set, or taken from the tour's prices for the party a departure runs with,
 * so it covers its worst case. A departure runs once the seats it runs with
 * are sold; its sales close or open again, and it is cancelled once its
 * bookings are.
 */
final readonly class TourDepartureService
{
    public const array REPEATS = ['once' => 0, 'week' => 7, 'fortnight' => 14];
    public const int MOST_AT_ONCE = 53;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private TourDepartureRepository $departures,
        private TourBookingRepository $bookings,
        private TourPriceService $prices,
    ) {
    }

    /**
     * @return list<TourDeparture>
     */
    public function departures(Tour $tour): array
    {
        return $this->departures->findBy(['tour' => $tour], ['start' => 'ASC']);
    }

    /**
     * The departures still to leave and not cancelled, the soonest first.
     *
     * @return list<TourDeparture>
     */
    public function upcoming(Tour $tour): array
    {
        $today = $this->clock->now()->setTime(0, 0);

        return array_values(array_filter($this->departures($tour), static fn (TourDeparture $d): bool => $d->getStart() >= $today && DepartureStatusEnum::Cancelled !== $d->getStatus()));
    }

    /**
     * The bookings standing on a departure, cancelled ones left out.
     *
     * @return list<TourBooking>
     */
    public function bookingsOn(TourDeparture $departure): array
    {
        return array_values(array_filter($this->bookings->findBy(['departure' => $departure], ['reference' => 'ASC']), static fn (TourBooking $b): bool => TourBookingStatusEnum::Cancelled !== $b->getStatus()));
    }

    public function sold(TourDeparture $departure): int
    {
        return array_sum(array_map(static fn (TourBooking $b): int => $b->getPeople(), $this->bookingsOn($departure)));
    }

    /** Where a departure stands: "Runs", "1 seat to run", "Full", "Sales closed", "Cancelled". */
    public static function standing(TourDeparture $departure, int $sold): string
    {
        $short = $departure->getRunsWith() - $sold;

        return match (true) {
            DepartureStatusEnum::Cancelled === $departure->getStatus() => 'Cancelled',
            DepartureStatusEnum::Closed === $departure->getStatus() => 'Sales closed',
            $sold >= $departure->getSeats() => 'Full',
            $short <= 0 => 'Runs',
            default => \sprintf('%d %s to run', $short, 1 === $short ? 'seat' : 'seats'),
        };
    }

    /**
     * @param array<mixed> $sent first, repeat, until, tier, seats, runs_with, seat
     *
     * @return int how many were added
     *
     * @throws InvalidTourException
     */
    public function add(Tour $tour, array $sent): int
    {
        $text = static fn (string $key): string => \is_string($sent[$key] ?? null) ? trim($sent[$key]) : '';
        $today = $this->clock->now()->setTime(0, 0);
        $first = self::day($text('first'));
        if (null === $first || $first < $today) {
            throw new InvalidTourException('first', 'The first day it leaves, today or later.');
        }
        $step = self::REPEATS[$text('repeat')] ?? throw new InvalidTourException('repeat', 'Once, every week, or every other week.');
        $days = [$first];
        if ($step > 0) {
            $until = self::day($text('until'));
            if (null === $until || $until < $first || $until > $first->modify('+1 year')) {
                throw new InvalidTourException('until', 'The last day one may leave, on or after the first and within a year of it.');
            }
            for ($day = $first->modify(\sprintf('+%d days', $step)); $day <= $until; $day = $day->modify(\sprintf('+%d days', $step))) {
                $days[] = $day;
            }
        }
        $tiers = max(1, \count($tour->getTiers()));
        $tier = $text('tier');
        if (!ctype_digit($tier) || (int) $tier >= $tiers) {
            throw new InvalidTourException('tier', 'Choose a tier the tour is sold in.');
        }
        $seats = $text('seats');
        if (!ctype_digit($seats) || (int) $seats < 1 || (int) $seats > $tour->getGroupMax()) {
            throw new InvalidTourException('seats', \sprintf('From 1 to the %d people the tour takes.', $tour->getGroupMax()));
        }
        $runsWith = $text('runs_with');
        if (!ctype_digit($runsWith) || (int) $runsWith < 1 || (int) $runsWith > (int) $seats) {
            throw new InvalidTourException('runs_with', 'From 1 to the seats it has.');
        }
        $typedSeat = str_replace(',', '', $text('seat'));
        if ('' !== $typedSeat && 1 !== preg_match('{^\d{1,7}(\.\d{1,2})?$}D', $typedSeat)) {
            throw new InvalidTourException('seat', 'A seat’s price, to the cent: 1240.00.');
        }
        if ('' === $tour->getPriceCurrency()) {
            throw new InvalidTourException('seat', 'Set the tour’s currency and group sizes on the Prices tab first.');
        }

        $taken = array_map(static fn (TourDeparture $d): string => $d->getStart()->format('Y-m-d'), $this->departures($tour));
        $added = [];
        foreach ($days as $day) {
            if (\in_array($day->format('Y-m-d'), $taken, true)) {
                throw new InvalidTourException('first', \sprintf('There is a departure on %s already.', $day->format('D j M Y')));
            }
            $seat = (int) round((float) $typedSeat * 100);
            if ('' === $typedSeat) {
                $price = $this->prices->quote($tour, $day, (int) $runsWith, (int) $tier);
                if (!$price->priced) {
                    throw new InvalidTourException('seat', rtrim($price->says, '.').'; set a seat’s price.');
                }
                $seat = $price->each;
            }
            $added[] = new TourDeparture($tour, $day, (int) $tier, (int) $seats, (int) $runsWith, $seat);
        }
        if (\count($added) > self::MOST_AT_ONCE) {
            throw new InvalidTourException('until', \sprintf('At most %d departures at once.', self::MOST_AT_ONCE));
        }
        foreach ($added as $departure) {
            $this->entityManager->persist($departure);
        }
        $this->entityManager->flush();

        return \count($added);
    }

    /** Its sales closed if open, open again if closed. */
    public function toggleSales(TourDeparture $departure): void
    {
        $departure->setStatus(DepartureStatusEnum::Open === $departure->getStatus() ? DepartureStatusEnum::Closed : DepartureStatusEnum::Open);
        $this->entityManager->flush();
    }

    /**
     * @throws InvalidTourException
     */
    public function cancel(TourDeparture $departure, string $reason): void
    {
        $reason = trim($reason);
        $standing = \count($this->bookingsOn($departure));
        if (DepartureStatusEnum::Cancelled === $departure->getStatus()) {
            throw new InvalidTourException('reason', 'It is cancelled already.');
        }
        if ($standing > 0) {
            throw new InvalidTourException('reason', \sprintf('Cancel its %d %s first.', $standing, 1 === $standing ? 'booking' : 'bookings'));
        }
        if ('' === $reason || mb_strlen($reason) > TourDeparture::REASON_MAX_LENGTH) {
            throw new InvalidTourException('reason', \sprintf('Why it is cancelled, up to %d characters.', TourDeparture::REASON_MAX_LENGTH));
        }
        $departure->setCancelled($this->clock->now(), $reason);
        $this->entityManager->flush();
    }

    private static function day(string $typed): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $typed);

        return false === $day || $day->format('Y-m-d') !== $typed ? null : $day;
    }
}
