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
use Symfony\Component\Intl\Countries;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Vivutio\Bundle\PlaceBundle\Enum\ResidencyEnum;
use Vivutio\Bundle\PlaceBundle\Repository\DestinationRepository;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Enum\MealEnum;
use Vivutio\Touring\Enum\TourStatusEnum;
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Model\FeeQuote;
use Vivutio\Touring\Model\TourPrice;
use Vivutio\Touring\Repository\TourRepository;
use Vivutio\Touring\Service\ParkFeeService;
use Vivutio\Touring\Service\TourDepartureService;
use Vivutio\Touring\Service\TourPriceService;
use Vivutio\Touring\Service\TourSeasonService;
use Vivutio\Touring\Service\TourService;

/**
 * The tours: the register by status, a tour's page with its itinerary and
 * what the parks charge a party, its Configure page, and its itinerary edited
 * as a whole. Read with tours.read, written with tours.manage.
 */
final readonly class TourController
{
    public const string REGISTER = 'touring_tours';
    public const string ADD = 'touring_tour_add';
    public const string SHOW = 'touring_tour';
    public const string CONFIGURE = 'touring_tour_configure';
    public const string OPEN = 'touring_tour_open';
    public const string ARCHIVE = 'touring_tour_archive';
    public const string ITINERARY = 'touring_tour_itinerary';
    public const string PRICES = 'touring_tour_prices';

    public const string READ = 'tours.read';
    public const string MANAGE = 'tours.manage';

    private const array TOUR_FIELDS = ['name', 'summary', 'group_min', 'group_max', 'included', 'excluded'];

    public function __construct(
        private Environment $twig,
        private TourService $service,
        private ParkFeeService $fees,
        private TourPriceService $prices,
        private TourSeasonService $seasons,
        private TourDepartureService $departures,
        private TourRepository $tours,
        private DestinationRepository $destinations,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/tours', name: self::REGISTER, methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function register(Request $request): Response
    {
        return $this->registerPage(TourStatusEnum::tryFrom($request->query->getString('status')));
    }

    #[Route('/tours', name: self::ADD, methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function add(Request $request): Response
    {
        $payload = $request->getPayload();
        $name = $payload->getString('name');
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_tour_add', $payload->getString('_token')))) {
            return $this->registerPage(null, $name, expired: true);
        }

        try {
            $tour = $this->service->create($name);
        } catch (InvalidTourException $refusal) {
            return $this->registerPage(null, $name, [$refusal->field => $refusal->getMessage()]);
        }

        return $this->to($tour);
    }

    #[Route('/tours/{uuid}', name: self::SHOW, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function show(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Tour $tour,
    ): Response {
        return $this->tourPage($tour, $request);
    }

    #[Route('/tours/{uuid}/configure', name: self::CONFIGURE, requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(self::MANAGE)]
    public function configure(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Tour $tour,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->configurePage($tour, $this->typedTour($tour));
        }

        $payload = $request->getPayload();
        $typed = [];
        foreach (self::TOUR_FIELDS as $field) {
            $typed[$field] = $payload->getString($field);
        }
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_tour_configure', $payload->getString('_token')))) {
            return $this->configurePage($tour, $typed, expired: true);
        }

        try {
            $this->service->configure($tour, $typed);
        } catch (InvalidTourException $refusal) {
            return $this->configurePage($tour, $typed, [$refusal->field => $refusal->getMessage()]);
        }

        return $this->to($tour);
    }

    #[Route('/tours/{uuid}/open', name: self::OPEN, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function open(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Tour $tour,
    ): Response {
        if ($this->tokens->isTokenValid(new CsrfToken('touring_tour_status', $request->getPayload()->getString('_token')))) {
            try {
                $this->service->open($tour);
            } catch (InvalidTourException $refusal) {
                return $this->configurePage($tour, $this->typedTour($tour), [$refusal->field => $refusal->getMessage()]);
            }
        }

        return $this->to($tour);
    }

    #[Route('/tours/{uuid}/archive', name: self::ARCHIVE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function archive(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Tour $tour,
    ): Response {
        if ($this->tokens->isTokenValid(new CsrfToken('touring_tour_status', $request->getPayload()->getString('_token')))) {
            $this->service->archive($tour);
        }

        return $this->to($tour);
    }

    #[Route('/tours/{uuid}/itinerary', name: self::ITINERARY, requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(self::MANAGE)]
    public function itinerary(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Tour $tour,
    ): Response {
        if (!$request->isMethod('POST')) {
            $open = $request->query->getString('open');

            return $this->itineraryPage($tour, null, ctype_digit($open) ? (int) $open : null);
        }

        $sent = $request->getPayload()->all();
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_itinerary', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->itineraryPage($tour, $sent, null, expired: true);
        }

        try {
            $open = $this->service->saveItinerary($tour, $sent, \is_string($sent['step'] ?? null) ? $sent['step'] : 'save');
        } catch (InvalidTourException $refusal) {
            return $this->itineraryPage($tour, $sent, null, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::ITINERARY, null === $open ? ['uuid' => $tour->getUuid()] : ['uuid' => $tour->getUuid(), 'open' => $open]));
    }

    #[Route('/tours/{uuid}/prices', name: self::PRICES, requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(self::MANAGE)]
    public function prices(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Tour $tour,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->pricesPage($tour, null);
        }
        $sent = $request->getPayload()->all();
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_prices', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->pricesPage($tour, $sent, expired: true);
        }

        try {
            $this->prices->save($tour, $sent);
        } catch (InvalidTourException $refusal) {
            return $this->pricesPage($tour, $sent, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::PRICES, ['uuid' => $tour->getUuid()]));
    }

    /**
     * @param array<mixed>|null     $sent
     * @param array<string, string> $wrong
     */
    private function pricesPage(Tour $tour, ?array $sent, array $wrong = [], bool $expired = false): Response
    {
        $brackets = $tour->getBrackets();

        return new Response($this->twig->render('@VivutioTouring/tours/prices.html.twig', [
            'tour' => $tour,
            'currency' => null === $sent ? $tour->getPriceCurrency() : (\is_string($sent['currency'] ?? null) ? $sent['currency'] : ''),
            'brackets_typed' => null === $sent ? implode(', ', array_map(static fn (array $b): string => $b[0] === $b[1] ? (string) $b[0] : $b[0].'-'.$b[1], $brackets)) : (\is_string($sent['brackets'] ?? null) ? $sent['brackets'] : ''),
            'brackets' => array_map(static fn (array $b): array => ['key' => $b[0].'-'.$b[1], 'label' => $b[0] === $b[1] ? $b[0].' people' : TourPriceService::size($b)], $brackets),
            'tiers' => [] === $tour->getTiers() ? ['The tour'] : $tour->getTiers(),
            'seasons' => $this->seasons->seasons(),
            'card' => null === $sent || !\is_array($sent['rates'] ?? null) ? $this->prices->card($tour) : $sent['rates'],
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function to(Tour $tour): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(self::SHOW, ['uuid' => $tour->getUuid()]));
    }

    /**
     * @param array<string, string> $wrong
     */
    private function registerPage(?TourStatusEnum $status, string $name = '', array $wrong = [], bool $expired = false): Response
    {
        $all = $this->tours->findBy([], ['name' => 'ASC']);
        $counts = array_fill_keys(array_map(static fn (TourStatusEnum $case): string => $case->value, TourStatusEnum::cases()), 0);
        foreach ($all as $tour) {
            ++$counts[$tour->getStatus()->value];
        }

        $from = [];
        foreach ($all as $tour) {
            $from[(string) $tour->getUuid()] = $this->prices->from($tour);
        }

        return new Response($this->twig->render('@VivutioTouring/tours/index.html.twig', [
            'tours' => array_values(array_filter($all, static fn (Tour $tour): bool => null === $status || $tour->getStatus() === $status)),
            'from' => $from,
            'total' => \count($all),
            'counts' => $counts,
            'statuses' => TourStatusEnum::cases(),
            'status' => $status,
            'name' => $name,
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function tourPage(Tour $tour, Request $request): Response
    {
        $titles = [];
        $stays = [];
        foreach ($tour->getDays() as $day) {
            $titles[$day->getNumber()] = $this->service->titleOf($day);
            $stays[$day->getNumber()] = $this->service->staysOf($day);
        }

        $departures = [];
        foreach ($this->departures->upcoming($tour) as $departure) {
            $sold = $this->departures->sold($departure);
            $departures[] = ['departure' => $departure, 'left' => $departure->getSeats() - $sold, 'standing' => TourDepartureService::standing($departure, $sold)];
        }

        return new Response($this->twig->render('@VivutioTouring/tours/show.html.twig', [
            'tour' => $tour,
            'departures' => $departures,
            'schedule' => $this->service->schedule($tour),
            'titles' => $titles,
            'stays' => $stays,
            'names' => $this->destinationNames(),
            'quote' => $this->quote($tour, $request),
            'price' => $this->price($tour, $request),
            'asked' => [
                'start' => $request->query->getString('start'),
                'adults' => $request->query->getString('adults', '2'),
                'children' => $request->query->getString('children', '0'),
                'residency' => $request->query->getString('residency', ResidencyEnum::NonResident->value),
                'tier' => $request->query->getString('tier', '0'),
            ],
            'tier_names' => [] === $tour->getTiers() ? ['The tour'] : $tour->getTiers(),
            'residencies' => ResidencyEnum::cases(),
        ]));
    }

    /**
     * @param array<string, string> $typed
     * @param array<string, string> $wrong
     */
    private function configurePage(Tour $tour, array $typed, array $wrong = [], bool $expired = false): Response
    {
        return new Response($this->twig->render('@VivutioTouring/tours/configure.html.twig', [
            'tour' => $tour,
            'typed' => $typed,
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The itinerary's page: what was typed when a step was refused, or what
     * is kept; the stay a step reached, or the one refused, is open.
     *
     * @param array<mixed>|null     $sent
     * @param array<string, string> $wrong
     */
    private function itineraryPage(Tour $tour, ?array $sent, ?int $open, array $wrong = [], bool $expired = false): Response
    {
        $byCountry = [];
        foreach ($this->destinations->findBy([], ['name' => 'ASC']) as $destination) {
            $byCountry[Countries::getName($destination->getCountry())][] = $destination;
        }
        ksort($byCountry);

        if (null === $sent) {
            $tiers = implode(', ', $tour->getTiers());
            $days = [];
            foreach ($tour->getDays() as $day) {
                $days[] = [
                    'title' => $day->getTitle(),
                    'destinations' => $day->getDestinations(),
                    'stays' => array_map(static fn (?string $stay): string => $stay ?? '', $day->getStays()),
                    'nights' => (string) $day->getNights(),
                    'meals' => $day->getMeals(),
                    'activities' => $day->getActivities(),
                    'description' => $day->getDescription(),
                    'distance_km' => null === $day->getDistanceKm() ? '' : (string) $day->getDistanceKm(),
                    'drive_hours' => null === $day->getDriveHours() ? '' : rtrim(rtrim($day->getDriveHours(), '0'), '.'),
                ];
            }
        } else {
            $tiers = \is_string($sent['tiers'] ?? null) ? $sent['tiers'] : '';
            $days = [];
            foreach (\is_array($sent['days'] ?? null) ? array_values($sent['days']) : [] as $day) {
                $day = \is_array($day) ? $day : [];
                $text = static fn (string $key): string => \is_string($day[$key] ?? null) ? $day[$key] : '';
                $days[] = [
                    'title' => $text('title'),
                    'destinations' => array_values(array_filter(\is_array($day['destinations'] ?? null) ? $day['destinations'] : [], 'is_string')),
                    'stays' => array_values(array_filter(\is_array($day['stays'] ?? null) ? $day['stays'] : [], 'is_string')),
                    'nights' => $text('nights'),
                    'meals' => array_keys(array_filter(\is_array($day['meals'] ?? null) ? $day['meals'] : [])),
                    'activities' => $text('activities'),
                    'description' => $text('description'),
                    'distance_km' => $text('distance_km'),
                    'drive_hours' => $text('drive_hours'),
                ];
            }
            foreach (array_keys($wrong) as $field) {
                if (1 === preg_match('{^days\[(\d+)\]}', $field, $at)) {
                    $open = (int) $at[1];
                }
            }
        }
        $tierNames = array_values(array_filter(array_map(trim(...), explode(',', $tiers))));

        return new Response($this->twig->render('@VivutioTouring/tours/itinerary.html.twig', [
            'tour' => $tour,
            'tiers' => $tiers,
            'tier_names' => [] === $tierNames ? ['The night at'] : $tierNames,
            'days' => $days,
            'open' => $open,
            'destination_choices' => $byCountry,
            'destination_names' => $this->destinationNames(),
            'overnight_choices' => $this->service->overnightChoices(),
            'meals' => MealEnum::cases(),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @return array<string, string>
     */
    private function typedTour(Tour $tour): array
    {
        return [
            'name' => $tour->getName(),
            'summary' => $tour->getSummary(),
            'group_min' => (string) $tour->getGroupMin(),
            'group_max' => (string) $tour->getGroupMax(),
            'included' => implode("\n", $tour->getIncluded()),
            'excluded' => implode("\n", $tour->getExcluded()),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function destinationNames(): array
    {
        $names = [];
        foreach ($this->destinations->findAll() as $destination) {
            $names[$destination->getKey()] = $destination->getName();
        }

        return $names;
    }

    /**
     * The tour's price for the party asked about, when a start day is asked.
     */
    private function price(Tour $tour, Request $request): ?TourPrice
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('start'));
        $adults = $request->query->getString('adults');
        $children = $request->query->getString('children', '0');
        $tier = $request->query->getString('tier', '0');
        if (false === $date || !ctype_digit($adults) || !ctype_digit($children) || !ctype_digit($tier)) {
            return null;
        }

        return $this->prices->quote($tour, $date, (int) $adults + (int) $children, (int) $tier);
    }

    /** The park fees for the party asked about, when a start day is asked. */
    private function quote(Tour $tour, Request $request): ?FeeQuote
    {
        $start = $request->query->getString('start');
        $date = 1 === preg_match('{^\d{4}-\d{2}-\d{2}$}D', $start) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $start) : false;
        $adults = $request->query->getString('adults');
        $children = $request->query->getString('children', '0');
        $residency = ResidencyEnum::tryFrom($request->query->getString('residency'));
        if (false === $date || null === $residency || !ctype_digit($adults) || !ctype_digit($children)
            || (int) $adults < 1 || (int) $adults + (int) $children > ParkFeeService::LARGEST_PARTY) {
            return null;
        }

        return $this->fees->quote($tour, $date, (int) $adults, (int) $children, $residency);
    }
}
