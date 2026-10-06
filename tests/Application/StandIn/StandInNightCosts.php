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

namespace Vivutio\Touring\Tests\Application\StandIn;

use Vivutio\Contracts\Stay\NightCost;
use Vivutio\Contracts\Stay\NightCostSourceInterface;

/**
 * What a night at the stand-in lodge costs a person sharing: USD 130.00 from
 * July to September, nothing agreed for April and May, USD 95.00 otherwise.
 */
final class StandInNightCosts implements NightCostSourceInterface
{
    public function kind(): string
    {
        return StandInPlaces::KIND;
    }

    public function cost(string $id, \DateTimeImmutable $night): ?NightCost
    {
        $month = (int) $night->format('n');

        return match (true) {
            StandInPlaces::LODGE !== $id, 4 === $month, 5 === $month => null,
            $month >= 7 && $month <= 9 => new NightCost('USD', 13000, 'Full board, sharing'),
            default => new NightCost('USD', 9500, 'Full board, sharing'),
        };
    }
}
