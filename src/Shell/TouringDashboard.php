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

namespace Vivutio\Touring\Shell;

use Psr\Clock\ClockInterface;
use Vivutio\Contracts\Shell\DashboardCard;
use Vivutio\Contracts\Shell\DashboardFigure;
use Vivutio\Contracts\Shell\DashboardRow;
use Vivutio\Contracts\Shell\DashboardSourceInterface;
use Vivutio\Touring\Controller\TourBookingController;
use Vivutio\Touring\Controller\TourController;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Enum\TourBookingStatusEnum;
use Vivutio\Touring\Repository\TourBookingRepository;
use Vivutio\Touring\Repository\TourDepartureRepository;
use Vivutio\Touring\Repository\TourRepository;
use Vivutio\Touring\Service\TourBookingService;
use Vivutio\Touring\Service\TourDepartureService;
use Vivutio\Touring\Service\TourService;

/**
 * Tours on the organization's dashboard, as drawn (vivutio-designs
 * shell/dashboard): who is travelling today, what starts in the next three
 * weeks and what is held, in the band; the bookings starting soon, those held
 * to confirm and the next departures, as cards. A held booking counts until
 * its hold lapses.
 */
final readonly class TouringDashboard implements DashboardSourceInterface
{
    /** How far ahead "soon" looks, in days. */
    public const int SOON = 21;

    /** A hold letting go within this many days is warned of. */
    public const int CLOSE = 3;

    public function __construct(
        private TourBookingRepository $bookings,
        private TourDepartureRepository $departures,
        private TourRepository $tours,
        private TourBookingService $booking,
        private TourDepartureService $seats,
        private TourService $schedules,
        private ClockInterface $clock,
    ) {
    }

    public function figures(): iterable
    {
        $today = $this->today();

        $travelling = $this->travelling($today);
        $people = array_sum(array_map(static fn (TourBooking $b): int => $b->getPeople(), $travelling));
        yield new DashboardFigure(
            'Travelling now',
            (string) \count($travelling),
            [] === $travelling ? '' : \sprintf('%s, %d %s', 1 === \count($travelling) ? 'party' : 'parties', $people, 1 === $people ? 'person' : 'people'),
            TourBookingController::READ,
            10,
        );

        $starting = \count($this->starting($today));
        yield new DashboardFigure('Starting in 3 weeks', (string) $starting, 0 === $starting ? '' : (1 === $starting ? 'booking' : 'bookings'), TourBookingController::READ, 20);

        $held = $this->bookings->heldOn($today);
        yield new DashboardFigure('Held, to confirm', (string) \count($held), self::lettingGo($held, $today), TourBookingController::READ, 30);
    }

    public function cards(): iterable
    {
        $today = $this->today();

        $starting = $this->starting($today);
        yield new DashboardCard(
            'Starting soon',
            'Tour bookings',
            'the next three weeks',
            array_map(fn (TourBooking $b): DashboardRow => new DashboardRow(
                $b->getReference(),
                $this->party($b),
                [$b->getTour()->getName(), self::day($b->getStart(), $today)],
                TourBookingStatusEnum::Confirmed === $b->getStatus() ? 'Confirmed' : 'Held',
                TourBookingStatusEnum::Confirmed === $b->getStatus() ? '' : 'warn',
            ), \array_slice($starting, 0, DashboardCard::MOST_ROWS)),
            \count($starting),
            'Nothing starts in the next three weeks',
            'All tour bookings',
            TourBookingController::REGISTER,
            TourBookingController::READ,
            order: 10,
        );

        $held = $this->bookings->heldOn($today);
        yield new DashboardCard(
            'Held, to confirm',
            'Tour bookings',
            'the soonest let go first',
            array_map(fn (TourBooking $b): DashboardRow => $this->heldRow($b, $today), \array_slice($held, 0, DashboardCard::MOST_ROWS)),
            \count($held),
            'Nothing is held',
            'All held bookings',
            TourBookingController::REGISTER,
            TourBookingController::READ,
            ['status' => TourBookingStatusEnum::Provisional->value],
            20,
        );

        $ahead = $this->departures->aheadOf($today);
        $rows = [];
        foreach (\array_slice($ahead, 0, DashboardCard::MOST_ROWS) as $departure) {
            $rows[] = new DashboardRow(
                $departure->getTour()->getName(),
                self::day($departure->getStart(), $today),
                [\sprintf('%d of %d places sold', $this->seats->sold($departure), $departure->getSeats())],
            );
        }
        yield new DashboardCard(
            'Next departures',
            'Tours',
            'fixed dates, places sold',
            $rows,
            \count($ahead),
            'No departures ahead',
            'All tours',
            TourController::REGISTER,
            TourController::READ,
            order: 50,
        );
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }

    /**
     * The confirmed bookings whose tour is under way today.
     *
     * @return list<TourBooking>
     */
    private function travelling(\DateTimeImmutable $today): array
    {
        $longest = 0;
        foreach ($this->tours->withDays() as $tour) {
            $longest = max($longest, $this->schedules->schedule($tour)['days']);
        }
        if (0 === $longest) {
            return [];
        }

        return array_values(array_filter(
            $this->bookings->confirmedBetween($today->modify(\sprintf('-%d days', $longest - 1)), $today),
            fn (TourBooking $b): bool => self::daysBetween($b->getStart(), $today) < $this->schedules->schedule($b->getTour())['days'],
        ));
    }

    /**
     * The bookings confirmed or held that start from tomorrow through three weeks on.
     *
     * @return list<TourBooking>
     */
    private function starting(\DateTimeImmutable $today): array
    {
        return $this->bookings->standingBetween($today->modify('+1 day'), $today->modify(\sprintf('+%d days', self::SOON)), $today);
    }

    /** The guest, and the partner it came through or whoever booked it, as the register shows them. */
    private function party(TourBooking $booking): string
    {
        $through = $this->booking->partnerOf($booking)?->getName() ?? $booking->getBookedBy();

        return null === $through || '' === $through ? $booking->getGuest() : $booking->getGuest().' · '.$through;
    }

    private function heldRow(TourBooking $booking, \DateTimeImmutable $today): DashboardRow
    {
        $until = $booking->getHeldUntil();
        if (null === $until) {
            return new DashboardRow($booking->getReference(), $this->party($booking));
        }
        $days = self::daysBetween($today, $until);

        return new DashboardRow(
            $booking->getReference(),
            $this->party($booking),
            ['held to '.self::day($until, $today)],
            match (true) {
                0 === $days => 'today',
                1 === $days => 'tomorrow',
                7 === $days => 'in a week',
                default => \sprintf('in %d days', $days),
            },
            match (true) {
                $days <= 1 => 'danger',
                $days <= self::CLOSE => 'warn',
                default => '',
            },
        );
    }

    /**
     * "1 lets go tomorrow": how many holds end today, or else tomorrow; nothing when none ends that soon.
     *
     * @param list<TourBooking> $held
     */
    private static function lettingGo(array $held, \DateTimeImmutable $today): string
    {
        foreach (['today' => 0, 'tomorrow' => 1] as $when => $days) {
            $ending = \count(array_filter($held, static fn (TourBooking $b): bool => null !== $b->getHeldUntil() && $days === self::daysBetween($today, $b->getHeldUntil())));
            if ($ending > 0) {
                return \sprintf('%d %s go %s', $ending, 1 === $ending ? 'lets' : 'let', $when);
            }
        }

        return '';
    }

    /** "Sun 18 Oct", with the year when it is not this year's: "Thu 1 Jul 2027". */
    private static function day(\DateTimeImmutable $day, \DateTimeImmutable $today): string
    {
        return $day->format($day->format('Y') === $today->format('Y') ? 'D j M' : 'D j M Y');
    }

    /** The calendar days from one day to another, whatever their times. */
    private static function daysBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $utc = new \DateTimeZone('UTC');
        $difference = (new \DateTimeImmutable($from->format('Y-m-d'), $utc))->diff(new \DateTimeImmutable($to->format('Y-m-d'), $utc));

        return (int) $difference->days * ($difference->invert ? -1 : 1);
    }
}
