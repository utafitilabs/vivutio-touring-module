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

namespace Vivutio\Touring\Partner;

use Psr\Clock\ClockInterface;
use Vivutio\Contracts\Partner\RoomNeed;
use Vivutio\Contracts\Partner\RoomNeedSourceInterface;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Enum\TourBookingStatusEnum;
use Vivutio\Touring\Repository\TourBookingRepository;
use Vivutio\Touring\Service\TourService;

/**
 * A tour booking's nights at partners' lodges, as rooms needed: each stay
 * whose night in the booking's tier is at a partner's lodge, for the party,
 * from the day it begins for its nights, while the booking is still to start
 * and not cancelled.
 */
final readonly class TourRoomNeeds implements RoomNeedSourceInterface
{
    public const string KEY = 'tour_booking';

    public function __construct(
        private TourBookingRepository $bookings,
        private TourService $tours,
        private ClockInterface $clock,
    ) {
    }

    public function needs(): iterable
    {
        $today = $this->clock->now()->setTime(0, 0);
        foreach ($this->bookings->findBy([], ['start' => 'ASC', 'reference' => 'ASC']) as $booking) {
            if (TourBookingStatusEnum::Cancelled === $booking->getStatus() || $booking->getStart() < $today) {
                continue;
            }
            yield from $this->needsOf($booking);
        }
    }

    /**
     * @return iterable<RoomNeed>
     */
    private function needsOf(TourBooking $booking): iterable
    {
        $tour = $booking->getTour();
        $labels = $this->tours->schedule($tour)['labels'];
        $offset = 0;
        foreach ($tour->getDays() as $day) {
            $stay = $day->getStays()[$booking->getTier()] ?? null;
            [$kind, $id] = array_pad(explode(':', $stay ?? '', 2), 2, '');
            if ($day->getNights() > 0 && TourService::PARTNER_KIND === $kind && '' !== $id) {
                yield new RoomNeed(
                    \sprintf('%s:%s:%d', self::KEY, $booking->getUuid(), $day->getNumber()),
                    $id,
                    $booking->getReference().' · '.$labels[$day->getNumber()],
                    $booking->getGuest(),
                    $booking->getPeople(),
                    $booking->getStart()->modify(\sprintf('+%d days', $offset)),
                    $day->getNights(),
                    $tour->getName(),
                    $booking->getNotes() ?? '',
                );
            }
            $offset += $day->getLength();
        }
    }
}
