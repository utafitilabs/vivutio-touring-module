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
use Vivutio\Touring\Entity\TourRate;
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Repository\TourRateRepository;

/**
 * A tour's rate card: a price per person by tour season, tier and group size
 * bracket, in one currency; the brackets take every size the tour does, each
 * in exactly one. A party is priced by the season of its first day; an empty
 * cell is not sold. The card is what is sold from: a cost changing elsewhere
 * never moves it.
 */
final readonly class TourPriceService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TourRateRepository $rates,
        private TourSeasonService $seasons,
    ) {
    }

    /**
     * The card as kept: amounts by tier, season uuid and bracket key "3-4".
     *
     * @return array<int, array<string, array<string, string>>>
     */
    public function card(Tour $tour): array
    {
        $card = [];
        foreach ($this->rates->findBy(['tour' => $tour]) as $rate) {
            $card[$rate->getTier()][(string) $rate->getSeason()->getUuid()][$rate->getMinPeople().'-'.$rate->getMaxPeople()] = $rate->getAmount();
        }

        return $card;
    }

    /**
     * @param array<mixed> $sent currency, brackets, rates[tier][season][bracket]
     *
     * @throws InvalidTourException
     */
    public function save(Tour $tour, array $sent): void
    {
        $currency = strtoupper(trim(\is_string($sent['currency'] ?? null) ? $sent['currency'] : ''));
        if (1 !== preg_match('{^[A-Z]{3}$}D', $currency)) {
            throw new InvalidTourException('currency', 'A currency is its three-letter code: USD, TZS, EUR.');
        }
        $brackets = $this->brackets($tour, \is_string($sent['brackets'] ?? null) ? $sent['brackets'] : '');
        $keys = array_map(static fn (array $b): string => $b[0].'-'.$b[1], $brackets);

        $typed = \is_array($sent['rates'] ?? null) ? $sent['rates'] : null;
        $kept = $this->card($tour);
        $card = [];
        $tiers = max(1, \count($tour->getTiers()));
        foreach ($this->seasons->seasons() as $season) {
            $s = (string) $season->getUuid();
            for ($t = 0; $t < $tiers; ++$t) {
                foreach ($brackets as $i => [$min, $max]) {
                    $key = $keys[$i];
                    $row = \is_array($typed[$t] ?? null) ? $typed[$t] : [];
                    $cells = \is_array($row[$s] ?? null) ? $row[$s] : [];
                    $amount = null !== $typed && \is_string($cells[$key] ?? null)
                        ? trim(str_replace(',', '', $cells[$key]))
                        : ($kept[$t][$s][$key] ?? '');
                    if ('' === $amount) {
                        continue;
                    }
                    if (1 !== preg_match('{^\d{1,9}(\.\d{1,2})?$}D', $amount)) {
                        throw new InvalidTourException(\sprintf('rates[%d][%s][%s]', $t, $s, $key), 'A price is an amount to the cent: 2155.24.');
                    }
                    $card[] = [$season, $t, $min, $max, number_format((float) $amount, 2, '.', '')];
                }
            }
        }

        $tour->setPricing($currency, $brackets);
        foreach ($this->rates->findBy(['tour' => $tour]) as $old) {
            $this->entityManager->remove($old);
        }
        $this->entityManager->flush();
        foreach ($card as [$season, $t, $min, $max, $amount]) {
            $this->entityManager->persist(new TourRate($tour, $season, $t, $min, $max, $amount));
        }
        $this->entityManager->flush();
    }

    /**
     * What a party pays: the tier's price for the season of its first day and
     * its size, a person and in all; or why it has none.
     *
     * @return array{priced: bool, says: string}
     */
    public function quote(Tour $tour, \DateTimeImmutable $start, int $people, int $tier): array
    {
        $tiers = $tour->getTiers();
        $tierName = $tiers[$tier] ?? (0 === $tier ? 'The tour' : null);
        if ('' === $tour->getPriceCurrency() || null === $tierName) {
            return ['priced' => false, 'says' => 'The tour has no prices yet.'];
        }
        if ($people < $tour->getGroupMin() || $people > $tour->getGroupMax()) {
            return ['priced' => false, 'says' => \sprintf('The tour takes %d to %d people.', $tour->getGroupMin(), $tour->getGroupMax())];
        }
        $season = $this->seasons->seasonOn($start);
        if (null === $season) {
            return ['priced' => false, 'says' => \sprintf('No tour season covers %s.', $start->format('j M Y'))];
        }
        $bracket = null;
        foreach ($tour->getBrackets() as $b) {
            if ($people >= $b[0] && $people <= $b[1]) {
                $bracket = $b;
            }
        }
        $size = null === $bracket ? $people.' people' : self::size($bracket).' people';
        $rate = null === $bracket ? null : $this->rates->findOneBy(['tour' => $tour, 'season' => $season, 'tier' => $tier, 'minPeople' => $bracket[0]]);
        if (null === $rate) {
            return ['priced' => false, 'says' => \sprintf('%s is not priced for %s in %s.', $tierName, $size, $season->getName())];
        }
        $each = (float) $rate->getAmount();

        return ['priced' => true, 'says' => \sprintf('%s · %s · %s: %s %s a person, %s %s for %d', $tierName, $season->getName(), $size, $tour->getPriceCurrency(), number_format($each, 2), $tour->getPriceCurrency(), number_format($each * $people, 2), $people)];
    }

    /** "From USD 1,599.33", the least a person pays, or null when the tour has no prices. */
    public function from(Tour $tour): ?string
    {
        $least = null;
        foreach ($this->rates->findBy(['tour' => $tour]) as $rate) {
            $least = null === $least ? (float) $rate->getAmount() : min($least, (float) $rate->getAmount());
        }

        return null === $least ? null : \sprintf('From %s %s', $tour->getPriceCurrency(), number_format($least, 2));
    }

    /**
     * "2", "3 – 4": a bracket as its header says it.
     *
     * @param array{int, int} $bracket
     */
    public static function size(array $bracket): string
    {
        return $bracket[0] === $bracket[1] ? (string) $bracket[0] : $bracket[0].' – '.$bracket[1];
    }

    /**
     * The brackets as typed, "2, 3-4, 5-6": every size the tour takes in
     * exactly one.
     *
     * @return list<array{int, int}>
     *
     * @throws InvalidTourException
     */
    private function brackets(Tour $tour, string $typed): array
    {
        $wrong = new InvalidTourException('brackets', \sprintf('Each size the tour takes, from %d to %d, in one bracket: 2, 3-4, 5-6.', $tour->getGroupMin(), $tour->getGroupMax()));
        $brackets = [];
        foreach (explode(',', $typed) as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            if (1 !== preg_match('{^(\d{1,2})(?:\s*-\s*(\d{1,2}))?$}D', $part, $m)) {
                throw $wrong;
            }
            $min = (int) $m[1];
            $max = isset($m[2]) ? (int) $m[2] : $min;
            if ($max < $min) {
                throw $wrong;
            }
            $brackets[] = [$min, $max];
        }
        usort($brackets, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $next = $tour->getGroupMin();
        foreach ($brackets as [$min, $max]) {
            if ($min !== $next) {
                throw $wrong;
            }
            $next = $max + 1;
        }
        if ([] === $brackets || $next !== $tour->getGroupMax() + 1) {
            throw $wrong;
        }

        return $brackets;
    }
}
