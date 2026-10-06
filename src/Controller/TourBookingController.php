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

namespace Vivutio\Touring\Controller;

use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Vivutio\Bundle\PlaceBundle\Enum\ResidencyEnum;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Enum\TourBookingStatusEnum;
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Service\TourBookingService;
use Vivutio\Touring\Service\TourDepartureService;
use Vivutio\Touring\Service\TourService;

/**
 * Tour bookings, as drawn (vivutio-designs tours/bookings): the register, a
 * new booking priced before it is recorded, and a booking's page, confirmed or
 * cancelled there. Read with tour_bookings.read, recorded with
 * tour_bookings.record, confirmed and cancelled with tour_bookings.manage.
 */
final readonly class TourBookingController
{
    public const string REGISTER = 'touring_bookings';
    public const string NEW = 'touring_booking_new';
    public const string BOOKING = 'touring_booking';
    public const string CONFIRM = 'touring_booking_confirm';
    public const string CANCEL = 'touring_booking_cancel';

    public const string READ = 'tour_bookings.read';
    public const string RECORD = 'tour_bookings.record';
    public const string MANAGE = 'tour_bookings.manage';

    public function __construct(
        private Environment $twig,
        private TourBookingService $bookings,
        private TourService $tours,
        private TourDepartureService $departures,
        private ClockInterface $clock,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/tours/bookings', name: self::REGISTER, methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function register(Request $request): Response
    {
        $status = TourBookingStatusEnum::tryFrom($request->query->getString('status'));
        $counts = $this->bookings->counts();
        $bookings = $this->bookings->bookings($status);
        $partners = [];
        $lengths = [];
        foreach ($bookings as $booking) {
            $partners[(string) $booking->getUuid()] = $this->bookings->partnerOf($booking)?->getName();
            $lengths[(string) $booking->getUuid()] = $this->tours->schedule($booking->getTour())['days'];
        }

        return new Response($this->twig->render('@VivutioTouring/bookings/index.html.twig', [
            'bookings' => $bookings,
            'partners' => $partners,
            'lengths' => $lengths,
            'status' => $status,
            'statuses' => TourBookingStatusEnum::cases(),
            'counts' => $counts,
            'total' => array_sum($counts),
            'today' => $this->clock->now(),
        ]));
    }

    #[Route('/tours/bookings/new', name: self::NEW, methods: ['GET', 'POST'])]
    #[IsGranted(self::RECORD)]
    public function new(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->newPage(TourBookingService::typed($request->query->all()));
        }
        $sent = $request->getPayload()->all();
        $typed = TourBookingService::typed($sent);
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_booking_new', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->newPage($typed, expired: true);
        }
        if ('price' === ($sent['step'] ?? null)) {
            return $this->newPage($typed);
        }

        try {
            $booking = $this->bookings->record($typed);
        } catch (InvalidTourException $refusal) {
            return $this->newPage($typed, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::BOOKING, ['uuid' => $booking->getUuid()]));
    }

    #[Route('/tours/bookings/{uuid}', name: self::BOOKING, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function booking(
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        TourBooking $booking,
    ): Response {
        return $this->bookingPage($booking);
    }

    #[Route('/tours/bookings/{uuid}/confirm', name: self::CONFIRM, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function confirm(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        TourBooking $booking,
    ): Response {
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_booking_confirm', $request->getPayload()->getString('_token')))) {
            return $this->bookingPage($booking, expired: true);
        }

        try {
            $this->bookings->confirm($booking);
        } catch (InvalidTourException $refusal) {
            return $this->bookingPage($booking, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::BOOKING, ['uuid' => $booking->getUuid()]));
    }

    #[Route('/tours/bookings/{uuid}/cancel', name: self::CANCEL, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function cancel(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        TourBooking $booking,
    ): Response {
        $payload = $request->getPayload();
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_booking_cancel', $payload->getString('_token')))) {
            return $this->bookingPage($booking, expired: true);
        }

        try {
            $this->bookings->cancel($booking, $payload->getString('reason'));
        } catch (InvalidTourException $refusal) {
            return $this->bookingPage($booking, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::BOOKING, ['uuid' => $booking->getUuid()]));
    }

    /**
     * @param array<string, string> $typed
     * @param array<string, string> $wrong
     */
    private function newPage(array $typed, array $wrong = [], bool $expired = false): Response
    {
        $tours = $this->bookings->tours();
        $tiers = [];
        foreach ($tours as $tour) {
            $tiers[(string) $tour->getUuid()] = [] === $tour->getTiers() ? ['The tour'] : $tour->getTiers();
        }

        $departure = $this->bookings->departureOf($typed['departure']);
        $departures = [];
        if (null !== $departure) {
            foreach ($this->bookings->departuresOf($departure->getTour()) as $other) {
                $departures[] = ['departure' => $other, 'left' => $other->getSeats() - $this->departures->sold($other)];
            }
        }

        return new Response($this->twig->render('@VivutioTouring/bookings/new.html.twig', [
            'typed' => $typed,
            'departure' => $departure,
            'departures' => $departures,
            'tours' => $tours,
            'tiers' => $tiers[$typed['tour']] ?? ($tiers[array_key_first($tiers) ?? ''] ?? ['The tour']),
            'partners' => $this->bookings->partners(),
            'residencies' => ResidencyEnum::cases(),
            'quoted' => $this->bookings->price($typed),
            'today' => $this->clock->now(),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, string> $wrong
     */
    private function bookingPage(TourBooking $booking, array $wrong = [], bool $expired = false): Response
    {
        $length = $this->tours->schedule($booking->getTour())['days'];

        return new Response($this->twig->render('@VivutioTouring/bookings/booking.html.twig', [
            'booking' => $booking,
            'partner' => $this->bookings->partnerOf($booking),
            'share' => TourBookingService::share($booking->getDiscount()),
            'days' => $this->tours->dated($booking->getTour(), $booking->getStart(), $booking->getTier()),
            'length' => $length,
            'ends' => $booking->getStart()->modify(\sprintf('+%d days', max(0, $length - 1))),
            'residency' => ResidencyEnum::tryFrom($booking->getResidency()),
            'today' => $this->clock->now(),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
