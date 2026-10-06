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

/** Where a tour booking stands: held for a while, confirmed, or cancelled. */
enum TourBookingStatusEnum: string
{
    case Provisional = 'provisional';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Provisional => 'Provisional',
            self::Confirmed => 'Confirmed',
            self::Cancelled => 'Cancelled',
        };
    }
}
