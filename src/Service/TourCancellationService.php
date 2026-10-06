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
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Entity\TourTerms;
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Repository\TourTermsRepository;

/**
 * What cancelling a tour booking costs. The tours' terms, kept once, and a
 * tour's own, which follow them, charge nothing or are its own: from so many
 * days before a tour starts, so much of its price; earlier is free, and
 * cancelling later is never charged less. A booking keeps the tiers in force
 * when it is made and is charged by them, a share of what it was sold for.
 */
final readonly class TourCancellationService
{
    public const int MOST_TIERS = 6;
    public const int MOST_DAYS = 365;
    public const string FOLLOW = 'follow';
    public const string NO_CHARGE = 'no_charge';
    public const string OWN = 'own';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TourTermsRepository $terms,
    ) {
    }

    /**
     * The tours' tiers, the most days first.
     *
     * @return list<array{days: int, percent: int}>
     */
    public function terms(): array
    {
        return $this->row()?->getCancellation() ?? [];
    }

    /**
     * @param list<array{string, string}> $rows days before it starts, and the share charged
     *
     * @throws InvalidTourException
     */
    public function saveTerms(array $rows): void
    {
        $tiers = self::tiers($rows);
        $row = $this->row();
        if (null === $row) {
            $row = new TourTerms();
            $this->entityManager->persist($row);
        }
        $row->setCancellation($tiers);
        $this->entityManager->flush();
    }

    /**
     * @param list<array{string, string}> $rows
     *
     * @throws InvalidTourException
     */
    public function saveTour(Tour $tour, string $mode, array $rows): void
    {
        $tour->setCancellation(match ($mode) {
            self::FOLLOW => null,
            self::NO_CHARGE => [],
            self::OWN => [] === ($own = self::tiers($rows)) ? throw new InvalidTourException('tiers', 'Give its own tiers, or choose no charge.') : $own,
            default => throw new InvalidTourException('mode', 'Choose whether it follows the tours’ terms, charges nothing, or has its own tiers.'),
        });
        $this->entityManager->flush();
    }

    public static function modeOf(Tour $tour): string
    {
        return match (true) {
            null === $tour->getCancellation() => self::FOLLOW,
            [] === $tour->getCancellation() => self::NO_CHARGE,
            default => self::OWN,
        };
    }

    /**
     * The tiers a booking of a tour is made under now.
     *
     * @return list<array{days: int, percent: int}>
     */
    public function tiersOf(Tour $tour): array
    {
        return $tour->getCancellation() ?? $this->terms();
    }

    /**
     * Tiers as a guest reads them: "Free more than 60 days before it starts",
     * "25% from 60 to 31 days before".
     *
     * @param list<array{days: int, percent: int}> $tiers
     *
     * @return list<string>
     */
    public static function bands(array $tiers): array
    {
        if ([] === $tiers) {
            return ['No charge to cancel'];
        }
        $bands = [\sprintf('Free more than %d days before it starts', $tiers[0]['days'])];
        foreach ($tiers as $i => $tier) {
            $next = $tiers[$i + 1] ?? null;
            $bands[] = null === $next
                ? (0 === $tier['days'] ? \sprintf('%d%% on the day it starts, and after', $tier['percent']) : \sprintf('%d%% from %d days before, and after it starts', $tier['percent'], $tier['days']))
                : \sprintf('%d%% from %d to %d days before', $tier['percent'], $tier['days'], $next['days'] + 1);
        }

        return $bands;
    }

    /**
     * Tiers said short: "25% from 60 days, 50% from 30, 100% from 7".
     *
     * @param list<array{days: int, percent: int}> $tiers
     */
    public static function brief(array $tiers): string
    {
        if ([] === $tiers) {
            return 'no charge to cancel';
        }
        $said = [];
        foreach ($tiers as $i => $tier) {
            $said[] = \sprintf(0 === $i ? '%d%% from %d days' : '%d%% from %d', $tier['percent'], $tier['days']);
        }

        return implode(', ', $said);
    }

    /**
     * What cancelling a booking on a day costs: the days before it starts
     * (fewer than none once started), the share charged, and the charge in cents.
     *
     * @return array{days: int, percent: int, charge: int}
     */
    public static function charge(TourBooking $booking, \DateTimeImmutable $on): array
    {
        $days = (int) $on->setTime(0, 0)->diff($booking->getStart())->format('%r%a');
        $tiers = $booking->getCancellationTiers();
        $percent = 0;
        if ([] !== $tiers) {
            $percent = $days <= 0 ? $tiers[\count($tiers) - 1]['percent'] : 0;
            foreach ($tiers as $tier) {
                if ($days > 0 && $days <= $tier['days']) {
                    $percent = $tier['percent'];
                }
            }
        }

        return ['days' => $days, 'percent' => $percent, 'charge' => intdiv($booking->getTotal() * $percent + 50, 100)];
    }

    /** "56 days before it starts", "on the day it starts", "2 days after it started". */
    public static function when(int $days): string
    {
        return match (true) {
            0 === $days => 'on the day it starts',
            $days < 0 => \sprintf('%d %s after it started', -$days, -1 === $days ? 'day' : 'days'),
            default => \sprintf('%d %s before it starts', $days, 1 === $days ? 'day' : 'days'),
        };
    }

    /** What cancelling a booking today would cost, as its page says it. */
    public static function today(TourBooking $booking, \DateTimeImmutable $today): string
    {
        if ([] === $booking->getCancellationTiers()) {
            return 'Cancelling today is free.';
        }
        $charge = self::charge($booking, $today);

        return 0 === $charge['charge']
            ? \sprintf('Cancelling today, %s, is free.', self::when($charge['days']))
            : \sprintf('Cancelling today, %s, would cost %s (%d%%).', self::when($charge['days']), TourPriceService::money($booking->getCurrency(), $charge['charge']), $charge['percent']);
    }

    /**
     * Typed rows as kept: whole days and whole per cent, the most days first,
     * each count of days once, and never less charged for cancelling later.
     * A row left empty is left out.
     *
     * @param list<array{string, string}> $rows
     *
     * @return list<array{days: int, percent: int}>
     *
     * @throws InvalidTourException
     */
    private static function tiers(array $rows): array
    {
        $tiers = [];
        foreach ($rows as [$days, $percent]) {
            $days = trim($days);
            $percent = trim($percent);
            if ('' === $days && '' === $percent) {
                continue;
            }
            if (!ctype_digit($days) || (int) $days > self::MOST_DAYS) {
                throw new InvalidTourException('tiers', \sprintf('Days before it starts are a whole number of days, from 0 to %d.', self::MOST_DAYS));
            }
            if (!ctype_digit($percent) || (int) $percent < 1 || (int) $percent > 100) {
                throw new InvalidTourException('tiers', 'What is charged is a share of the price, from 1 to 100 per cent.');
            }
            foreach ($tiers as $tier) {
                if ($tier['days'] === (int) $days) {
                    throw new InvalidTourException('tiers', \sprintf('%d days before it starts is given twice: each count of days once.', (int) $days));
                }
            }
            $tiers[] = ['days' => (int) $days, 'percent' => (int) $percent];
        }
        if (\count($tiers) > self::MOST_TIERS) {
            throw new InvalidTourException('tiers', \sprintf('At most %d tiers.', self::MOST_TIERS));
        }
        usort($tiers, static fn (array $a, array $b): int => $b['days'] <=> $a['days']);
        foreach ($tiers as $i => $tier) {
            $earlier = $tiers[$i - 1] ?? null;
            if (null !== $earlier && $tier['percent'] < $earlier['percent']) {
                throw new InvalidTourException('tiers', \sprintf('Cancelling %d days before charges less (%d%%) than %d days before (%d%%): a later cancellation is never charged less.', $tier['days'], $tier['percent'], $earlier['days'], $earlier['percent']));
            }
        }

        return $tiers;
    }

    private function row(): ?TourTerms
    {
        return $this->terms->findOneBy([], ['id' => 'ASC']);
    }
}
