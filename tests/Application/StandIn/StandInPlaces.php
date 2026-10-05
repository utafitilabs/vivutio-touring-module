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

use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\Contracts\Place\PlaceSourceInterface;

/**
 * A package's place, played by an invented lodge.
 */
final class StandInPlaces implements PlaceSourceInterface
{
    public const string KIND = 'lodge';
    public const string LODGE = '0199b1c0-0000-7000-8000-00000000d001';

    public static function lodge(): PlaceInterface
    {
        return new class implements PlaceInterface {
            public function getPlaceKind(): string
            {
                return StandInPlaces::KIND;
            }

            public function getPlaceId(): string
            {
                return StandInPlaces::LODGE;
            }

            public function getName(): string
            {
                return 'Vivutio Stand-in Lodge';
            }
        };
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Lodges';
    }

    public function places(): iterable
    {
        yield self::lodge();
    }

    public function find(string $id): ?PlaceInterface
    {
        return self::LODGE === $id ? self::lodge() : null;
    }
}
