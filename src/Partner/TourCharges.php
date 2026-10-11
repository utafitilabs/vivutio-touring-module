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

use Vivutio\Contracts\Partner\Charge;
use Vivutio\Contracts\Partner\ChargeSourceInterface;
use Vivutio\Touring\Controller\TourBookingController;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Enum\TourBookingStatusEnum;
use Vivutio\Touring\Repository\TourBookingRepository;
use Vivutio\Touring\Service\TourService;

/**
 * What tour bookings are owed, as charges: a confirmed booking its total,
 * from the day it was made, due then from a guest and in its partner's days to
 * pay from a partner; a cancelled one what its cancellation kept, from the day
 * it was cancelled; a provisional one nothing yet.
 */
final readonly class TourCharges implements ChargeSourceInterface
{
    public function __construct(
        private TourBookingRepository $bookings,
        private TourService $tours,
    ) {
    }

    public function charges(): iterable
    {
        foreach ($this->bookings->findBy([], ['start' => 'ASC', 'reference' => 'ASC']) as $booking) {
            $cancelled = TourBookingStatusEnum::Cancelled === $booking->getStatus();
            if (TourBookingStatusEnum::Provisional === $booking->getStatus()) {
                continue;
            }
            $raised = ($cancelled ? $booking->getCancelledAt() ?? $booking->getMadeAt() : $booking->getMadeAt())->setTime(0, 0);
            yield new Charge(
                TourRoomNeeds::KEY.':'.$booking->getUuid(),
                $booking->getReference(),
                'Tour booking',
                $booking->getTour()->getName().', '.$this->dates($booking).($cancelled ? ', cancelled' : ''),
                $booking->getPartnerId(),
                $booking->getGuest(),
                $booking->getCurrency(),
                $cancelled ? $booking->getCancellationCharge() ?? 0 : $booking->getTotal(),
                $raised,
                $raised->modify(\sprintf('+%d days', $cancelled || null === $booking->getPartnerId() ? 0 : $booking->getCreditDays())),
                TourBookingController::BOOKING,
                ['uuid' => (string) $booking->getUuid()],
            );
        }
    }

    /** "2 – 7 Aug 2027", the booking's first day to its last. */
    private function dates(TourBooking $booking): string
    {
        $first = $booking->getStart();
        $last = $first->modify(\sprintf('+%d days', max(0, $this->tours->schedule($booking->getTour())['days'] - 1)));

        return match (true) {
            $first == $last => $first->format('j M Y'),
            $first->format('Y-m') === $last->format('Y-m') => $first->format('j').' – '.$last->format('j M Y'),
            $first->format('Y') === $last->format('Y') => $first->format('j M').' – '.$last->format('j M Y'),
            default => $first->format('j M Y').' – '.$last->format('j M Y'),
        };
    }
}
