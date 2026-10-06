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

namespace Vivutio\Touring\Enum;

/** How busy a tour season is, drawn as its tone on the calendar. */
enum SeasonToneEnum: int
{
    case Quiet = 1;
    case Busy = 2;
    case Busiest = 3;

    public function label(): string
    {
        return match ($this) {
            self::Quiet => 'Quiet',
            self::Busy => 'Busy',
            self::Busiest => 'Busiest',
        };
    }
}
