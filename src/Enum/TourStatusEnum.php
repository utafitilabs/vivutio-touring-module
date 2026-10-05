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

/**
 * Where a tour stands: written but not yet sold, open for sale, or archived,
 * kept with everything it has and sold no more.
 */
enum TourStatusEnum: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open',
            self::Archived => 'Archived',
        };
    }
}
