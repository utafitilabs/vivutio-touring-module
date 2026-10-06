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

namespace Vivutio\Touring\Access;

use Vivutio\Contracts\Access\Concern;
use Vivutio\Contracts\Access\ConcernSourceInterface;
use Vivutio\Contracts\Access\Scope;
use Vivutio\Contracts\Access\Verb;

/**
 * What a position may grant about tours: reading them and what the parks
 * charge, and writing them.
 */
final readonly class TouringConcerns implements ConcernSourceInterface
{
    public const string TOURS = 'tours';
    public const string TOUR_BOOKINGS = 'tour_bookings';

    public function declaredBy(): string
    {
        return 'Touring';
    }

    public function concerns(): iterable
    {
        yield new Concern(
            key: self::TOURS,
            label: 'Tours',
            description: 'The tours sold, day by day, and what the parks charge a party on them: reading them, and writing them.',
            verbs: [Verb::Read, Verb::Manage],
            scopes: [Scope::ORGANIZATION],
            moduleSlug: 'touring',
        );

        yield new Concern(
            key: self::TOUR_BOOKINGS,
            label: 'Tour bookings',
            description: 'The tours sold: reading the bookings, recording one, and confirming or cancelling it.',
            verbs: [Verb::Read, Verb::Record, Verb::Manage],
            scopes: [Scope::ORGANIZATION],
            moduleSlug: 'touring',
        );
    }
}
