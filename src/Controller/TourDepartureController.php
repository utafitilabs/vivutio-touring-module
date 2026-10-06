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
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourDeparture;
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Service\TourDepartureService;
use Vivutio\Touring\Service\TourService;

/**
 * A tour's departures, as drawn (vivutio-designs tours/departures, A and E):
 * kept on its Configure › Departures tab with tours.manage, a run of them
 * added at once; each with its own page, read with tours.read, where its
 * sales close or open again and it is cancelled.
 */
final readonly class TourDepartureController
{
    public const string DEPARTURES = 'touring_tour_departures';
    public const string DEPARTURE = 'touring_departure';
    public const string SALES = 'touring_departure_sales';
    public const string CANCEL = 'touring_departure_cancel';

    public function __construct(
        private Environment $twig,
        private TourDepartureService $departures,
        private TourService $tours,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/tours/{uuid}/departures', name: self::DEPARTURES, requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(TourController::MANAGE)]
    public function departures(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Tour $tour,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->departuresPage($tour, ['repeat' => 'once', 'tier' => '0', 'seats' => (string) $tour->getGroupMax(), 'runs_with' => (string) $tour->getGroupMin()]);
        }
        $sent = $request->getPayload()->all();
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_departures', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->departuresPage($tour, $sent, expired: true);
        }

        try {
            $this->departures->add($tour, $sent);
        } catch (InvalidTourException $refusal) {
            return $this->departuresPage($tour, $sent, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::DEPARTURES, ['uuid' => $tour->getUuid()]));
    }

    #[Route('/tours/departures/{uuid}', name: self::DEPARTURE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(TourController::READ)]
    public function departure(
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        TourDeparture $departure,
    ): Response {
        return $this->departurePage($departure);
    }

    #[Route('/tours/departures/{uuid}/sales', name: self::SALES, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TourController::MANAGE)]
    public function sales(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        TourDeparture $departure,
    ): Response {
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_departure_sales', $request->getPayload()->getString('_token')))) {
            return $this->departurePage($departure, expired: true);
        }
        $this->departures->toggleSales($departure);

        return new RedirectResponse($this->urls->generate(self::DEPARTURE, ['uuid' => $departure->getUuid()]));
    }

    #[Route('/tours/departures/{uuid}/cancel', name: self::CANCEL, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TourController::MANAGE)]
    public function cancel(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        TourDeparture $departure,
    ): Response {
        $payload = $request->getPayload();
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_departure_cancel', $payload->getString('_token')))) {
            return $this->departurePage($departure, expired: true);
        }

        try {
            $this->departures->cancel($departure, $payload->getString('reason'));
        } catch (InvalidTourException $refusal) {
            return $this->departurePage($departure, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::DEPARTURE, ['uuid' => $departure->getUuid()]));
    }

    /**
     * @param array<mixed>          $typed
     * @param array<string, string> $wrong
     */
    private function departuresPage(Tour $tour, array $typed, array $wrong = [], bool $expired = false): Response
    {
        $rows = [];
        foreach ($this->departures->departures($tour) as $departure) {
            $sold = $this->departures->sold($departure);
            $rows[] = ['departure' => $departure, 'sold' => $sold, 'standing' => TourDepartureService::standing($departure, $sold)];
        }

        return new Response($this->twig->render('@VivutioTouring/tours/departures.html.twig', [
            'tour' => $tour,
            'rows' => $rows,
            'tiers' => [] === $tour->getTiers() ? ['The tour'] : $tour->getTiers(),
            'typed' => $typed,
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, string> $wrong
     */
    private function departurePage(TourDeparture $departure, array $wrong = [], bool $expired = false): Response
    {
        $bookings = $this->departures->bookingsOn($departure);
        $sold = $this->departures->sold($departure);
        $tour = $departure->getTour();

        return new Response($this->twig->render('@VivutioTouring/departures/departure.html.twig', [
            'departure' => $departure,
            'tour' => $tour,
            'tier' => $tour->getTiers()[$departure->getTier()] ?? 'The tour',
            'bookings' => $bookings,
            'sold' => $sold,
            'takings' => array_sum(array_map(static fn ($booking): int => $booking->getTotal(), $bookings)),
            'standing' => TourDepartureService::standing($departure, $sold),
            'length' => $this->tours->schedule($tour)['days'],
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
