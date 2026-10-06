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

namespace Vivutio\Touring\Model;

/**
 * What the parks charge a party on a tour: each day's fees by currency, the
 * whole tour's, and each destination on a day for which no fee is entered.
 */
final readonly class FeeQuote
{
    /**
     * @param array<int, array<string, int>> $days    by stay, in the itinerary's order, cents by currency
     * @param array<string, int>             $totals  cents by currency
     * @param list<string>                   $missing "No fee entered for Lake Manyara National Park on 5 Nov 2026"
     */
    public function __construct(
        public \DateTimeImmutable $start,
        public int $adults,
        public int $children,
        public string $residency,
        public array $days,
        public array $totals,
        public array $missing,
    ) {
    }
}
