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
use Vivutio\Bundle\PlaceBundle\Enum\ResidencyEnum;
use Vivutio\Bundle\PlaceBundle\Service\NightCostService;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourSeason;
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Repository\TourRateRepository;

/**
 * What a tour costs a person, beside what it is sold at: for each tier,
 * season and group size, the nights at each lodge (from the package that
 * keeps the lodge, through the core), the parks' fees (from the core) and the
 * tour's own costs, a group cost shared by the smallest party of the size;
 * each season costed from its first day to come, for non-residents. The
 * costs never change a price by themselves: a tier's prices are taken over
 * at the margin wanted only when asked.
 *
 * @phpstan-type CostCell array{key: string, label: string, title: string, bracket: string, people: int, start: ?\DateTimeImmutable, price: ?int, cost: ?int, missing: ?string, margin: ?float, suggested: ?int, lines: list<array{label: string, amount: int}>}
 */
final readonly class TourCostService
{
    public const string PER_GROUP = 'group';
    public const string PER_PERSON = 'person';
    public const int MOST_COSTS = 12;
    public const int NAME_MAX_LENGTH = 80;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private NightCostService $nights,
        private ParkFeeService $fees,
        private TourSeasonService $seasons,
        private TourPriceService $prices,
        private TourService $tours,
        private TourRateRepository $rates,
    ) {
    }

    /**
     * Every cell of the rate card with its cost, tier by tier, season by season.
     *
     * @return list<array{tier: int, name: string, rows: list<array{season: TourSeason, cells: list<CostCell>}>}>
     */
    public function sheet(Tour $tour): array
    {
        $prices = $this->priceCells($tour);
        $starts = [];
        $sheet = [];
        foreach (self::tierNames($tour) as $t => $name) {
            $rows = [];
            foreach ($this->seasons->seasons() as $season) {
                $starts[(string) $season->getUuid()] ??= $this->firstDayOf($season);
                $cells = [];
                foreach ($tour->getBrackets() as $bracket) {
                    $cells[] = $this->cell($tour, $t, $name, $season, $bracket, $starts[(string) $season->getUuid()], $prices[$t][(string) $season->getUuid()][$bracket[0]] ?? null);
                }
                $rows[] = ['season' => $season, 'cells' => $cells];
            }
            $sheet[] = ['tier' => $t, 'name' => $name, 'rows' => $rows];
        }

        return $sheet;
    }

    /**
     * @param array<mixed> $sent margin, costs[i][name|per|amount]
     *
     * @throws InvalidTourException
     */
    public function save(Tour $tour, array $sent): void
    {
        $text = static fn (mixed $value): string => \is_string($value) ? trim($value) : '';
        $margin = str_replace('%', '', $text($sent['margin'] ?? null));
        $margin = '' === $margin ? '0' : $margin;
        if (1 !== preg_match('{^\d{1,2}(\.\d{1,2})?$}D', $margin)) {
            throw new InvalidTourException('margin', 'A share of the price, under 100: 20.');
        }

        $costs = [];
        $typed = \is_array($sent['costs'] ?? null) ? array_values($sent['costs']) : [];
        foreach ($typed as $i => $row) {
            $row = \is_array($row) ? $row : [];
            [$name, $per, $amount] = [$text($row['name'] ?? null), $text($row['per'] ?? null), str_replace(',', '', $text($row['amount'] ?? null))];
            if ('' === $name && '' === $amount) {
                continue;
            }
            if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
                throw new InvalidTourException(\sprintf('costs[%d][name]', $i), \sprintf('What the cost is, up to %d characters: Vehicle.', self::NAME_MAX_LENGTH));
            }
            if (!\in_array($per, [self::PER_GROUP, self::PER_PERSON], true)) {
                throw new InvalidTourException(\sprintf('costs[%d][per]', $i), 'For the group, or a person.');
            }
            if (1 !== preg_match('{^\d{1,9}(\.\d{1,2})?$}D', $amount)) {
                throw new InvalidTourException(\sprintf('costs[%d][amount]', $i), 'An amount to the cent, in the tour’s currency: 900.00.');
            }
            $costs[] = ['name' => $name, 'per' => $per, 'amount' => (int) round((float) $amount * 100)];
        }
        if (\count($costs) > self::MOST_COSTS) {
            throw new InvalidTourException('costs[0][name]', \sprintf('At most %d costs.', self::MOST_COSTS));
        }

        $tour->setCosting(number_format((float) $margin, 2, '.', ''), $costs);
        $this->entityManager->flush();
    }

    /**
     * A tier's prices set to what its costs call for at the margin wanted,
     * cell by cell; a cell whose cost cannot be told keeps its price.
     *
     * @throws InvalidTourException
     */
    public function takeOver(Tour $tour, int $tier): void
    {
        if ('' === $tour->getPriceCurrency() || $tier < 0 || $tier >= max(1, \count($tour->getTiers()))) {
            throw new InvalidTourException('tier', 'Set the tour’s currency and group sizes on the Prices tab first, then take a tier’s prices over.');
        }
        $card = $this->prices->card($tour);
        foreach ($this->sheet($tour) as $tierSheet) {
            if ($tierSheet['tier'] !== $tier) {
                continue;
            }
            foreach ($tierSheet['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    if (\is_int($cell['suggested'])) {
                        $card[$tier][(string) $row['season']->getUuid()][$cell['bracket']] = number_format($cell['suggested'] / 100, 2, '.', '');
                    }
                }
            }
        }
        $brackets = implode(', ', array_map(static fn (array $b): string => $b[0] === $b[1] ? (string) $b[0] : $b[0].'-'.$b[1], $tour->getBrackets()));
        $this->prices->save($tour, ['currency' => $tour->getPriceCurrency(), 'brackets' => $brackets, 'rates' => $card]);
    }

    /**
     * One cell: its price and cost a person in cents, the margin, the price
     * the margin wanted calls for, and the lines of its cost; or why its cost
     * cannot be told.
     *
     * @param array{int, int} $bracket
     *
     * @return CostCell
     */
    private function cell(Tour $tour, int $tier, string $tierName, TourSeason $season, array $bracket, ?\DateTimeImmutable $start, ?int $price): array
    {
        $people = $bracket[0];
        $size = TourPriceService::size($bracket);
        $cell = [
            'key' => \sprintf('%d:%s:%d', $tier, $season->getUuid(), $bracket[0]),
            'label' => \sprintf('%s · %s · %s', $tierName, $season->getName(), $size),
            'title' => \sprintf('%s · %s · %s people', $tierName, $season->getName(), $size),
            'bracket' => $bracket[0].'-'.$bracket[1],
            'people' => $people,
            'start' => $start,
            'price' => $price,
            'cost' => null,
            'missing' => null,
            'margin' => null,
            'suggested' => null,
            'lines' => [],
        ];
        if (null === $start) {
            return [...$cell, 'missing' => \sprintf('No day in the coming year is in %s', $season->getName())];
        }

        $currency = $tour->getPriceCurrency();
        $labels = $this->tours->schedule($tour)['labels'];
        $lines = [];
        $missing = null;
        $offset = 0;
        foreach ($tour->getDays() as $day) {
            $label = $labels[$day->getNumber()];
            if ($day->getNights() > 0) {
                $stay = $day->getStays()[$tier] ?? null;
                if (null === $stay) {
                    $missing ??= \sprintf('No lodge set for %s on %s', $tierName, $label);
                } else {
                    $name = $this->tours->nameOf($stay);
                    $sum = 0;
                    for ($k = 0; $k < $day->getNights(); ++$k) {
                        $night = $start->modify(\sprintf('+%d days', $offset + $k));
                        $cost = $this->nights->costOf($stay, $night);
                        if (null === $cost) {
                            $missing ??= \sprintf('No rate for %s on %s', $name, $night->format('j M Y'));
                        } elseif ($cost->currency !== $currency) {
                            $missing ??= \sprintf('%s is priced in %s; the tour sells in %s', $name, $cost->currency, $currency);
                        } else {
                            $sum += $cost->each;
                        }
                    }
                    $lines[] = ['label' => \sprintf('%s · %s · %d %s', $label, $name, $day->getNights(), 1 === $day->getNights() ? 'night' : 'nights'), 'amount' => $sum];
                }
            }
            $offset += $day->getLength();
        }

        $fees = $this->fees->quote($tour, $start, $people, 0, ResidencyEnum::NonResident);
        $missing ??= $fees->missing[0] ?? null;
        foreach ($tour->getDays() as $day) {
            foreach ($fees->days[$day->getNumber()] ?? [] as $feeCurrency => $amount) {
                if ($feeCurrency !== $currency) {
                    $missing ??= \sprintf('The parks charge in %s; the tour sells in %s', $feeCurrency, $currency);
                    continue;
                }
                $lines[] = ['label' => $labels[$day->getNumber()].' · park fees', 'amount' => (int) round($amount / $people)];
            }
        }

        foreach ($tour->getCosts() as $own) {
            $lines[] = self::PER_GROUP === $own['per']
                ? ['label' => \sprintf('%s, shared by %d', $own['name'], $people), 'amount' => (int) round($own['amount'] / $people)]
                : ['label' => $own['name'], 'amount' => $own['amount']];
        }

        if (null !== $missing) {
            return [...$cell, 'missing' => $missing, 'lines' => $lines];
        }
        $cost = array_sum(array_column($lines, 'amount'));
        $wanted = (float) $tour->getMargin();

        return [
            ...$cell,
            'cost' => $cost,
            'lines' => $lines,
            'margin' => null === $price || 0 === $price ? null : ($price - $cost) / $price * 100,
            'suggested' => (int) ceil($cost * 100 / (100 - $wanted)),
        ];
    }

    /** The first day from today in a season, within a year; null when none is. */
    private function firstDayOf(TourSeason $season): ?\DateTimeImmutable
    {
        $day = $this->clock->now()->setTime(0, 0);
        for ($i = 0; $i < 366; ++$i, $day = $day->modify('+1 day')) {
            if ($this->seasons->seasonOn($day) === $season) {
                return $day;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, array<int, int>>> prices in cents, by tier, season uuid and smallest size
     */
    private function priceCells(Tour $tour): array
    {
        $cells = [];
        foreach ($this->rates->findBy(['tour' => $tour]) as $rate) {
            $cells[$rate->getTier()][(string) $rate->getSeason()->getUuid()][$rate->getMinPeople()] = (int) round((float) $rate->getAmount() * 100);
        }

        return $cells;
    }

    /**
     * @return list<string>
     */
    private static function tierNames(Tour $tour): array
    {
        return [] === $tour->getTiers() ? ['The tour'] : $tour->getTiers();
    }
}
