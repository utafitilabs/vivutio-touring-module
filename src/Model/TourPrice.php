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
 * What a party pays for a tour, by the season of its first day, or why the
 * tour's prices do not price it. Amounts are in cents of the currency.
 */
final readonly class TourPrice
{
    public function __construct(
        public bool $priced,
        public string $says,
        public string $tier = '',
        public string $season = '',
        public string $size = '',
        public string $currency = '',
        public int $each = 0,
        public int $people = 0,
    ) {
    }

    public static function refused(string $says): self
    {
        return new self(false, $says);
    }

    public function gross(): int
    {
        return $this->each * $this->people;
    }
}
