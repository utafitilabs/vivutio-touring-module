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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
use Vivutio\Touring\Entity\TourDay;
use Vivutio\Touring\Enum\MealEnum;
use Vivutio\Touring\Enum\TourStatusEnum;
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Model\FeeQuote;
use Vivutio\Touring\Repository\TourRepository;
use Vivutio\Touring\Service\ParkFeeService;
use Vivutio\Touring\Service\TourService;

/**
 * The tours: the register by status, a tour's page with its days and what the
 * parks charge a party, its Configure page, and its days. Read with
 * tours.read, written with tours.manage.
 */
final readonly class TourController
{
    public const string REGISTER = 'touring_tours';
    public const string ADD = 'touring_tour_add';
    public const string SHOW = 'touring_tour';
    public const string CONFIGURE = 'touring_tour_configure';
    public const string OPEN = 'touring_tour_open';
    public const string ARCHIVE = 'touring_tour_archive';
    public const string ADD_DAY = 'touring_day_add';
    public const string CONFIGURE_DAY = 'touring_day_configure';
    public const string REMOVE_DAY = 'touring_day_remove';

    public const string READ = 'tours.read';
    public const string MANAGE = 'tours.manage';

    private const array TOUR_FIELDS = ['name', 'summary', 'group_min', 'group_max', 'included', 'excluded'];

    public function __construct(
        private Environment $twig,
        private TourService $service,
        private ParkFeeService $fees,
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

    #[Route('/tours/{uuid}/days', name: self::ADD_DAY, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function addDay(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Tour $tour,
    ): Response {
        $sent = $request->getPayload()->all();
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_day', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->tourPage($tour, $request, $sent, expired: true);
        }

        try {
            $this->service->addDay($tour, $sent);
        } catch (InvalidTourException $refusal) {
            return $this->tourPage($tour, $request, $sent, [$refusal->field => $refusal->getMessage()]);
        }

        return $this->to($tour);
    }

    #[Route('/tours/{uuid}/days/{day}/configure', name: self::CONFIGURE_DAY, requirements: ['uuid' => Requirement::UUID, 'day' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(self::MANAGE)]
    public function configureDay(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Tour $tour,
        #[MapEntity(mapping: ['day' => 'uuid'])]
        TourDay $day,
    ): Response {
        $this->belongs($tour, $day);
        if (!$request->isMethod('POST')) {
            return $this->dayPage($day, self::typedDay($day));
        }

        $sent = $request->getPayload()->all();
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_day', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->dayPage($day, $sent, expired: true);
        }

        try {
            $this->service->changeDay($day, $sent);
        } catch (InvalidTourException $refusal) {
            return $this->dayPage($day, $sent, [$refusal->field => $refusal->getMessage()]);
        }

        return $this->to($tour);
    }

    #[Route('/tours/{uuid}/days/{day}/remove', name: self::REMOVE_DAY, requirements: ['uuid' => Requirement::UUID, 'day' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function removeDay(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Tour $tour,
        #[MapEntity(mapping: ['day' => 'uuid'])]
        TourDay $day,
    ): Response {
        $this->belongs($tour, $day);
        if ($this->tokens->isTokenValid(new CsrfToken('touring_day_remove', $request->getPayload()->getString('_token')))) {
            $this->service->removeDay($day);
        }

        return $this->to($tour);
    }

    private function belongs(Tour $tour, TourDay $day): void
    {
        if ($day->getTour() !== $tour) {
            throw new NotFoundHttpException();
        }
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

        return new Response($this->twig->render('@VivutioTouring/tours/index.html.twig', [
            'tours' => array_values(array_filter($all, static fn (Tour $tour): bool => null === $status || $tour->getStatus() === $status)),
            'total' => \count($all),
            'counts' => $counts,
            'statuses' => TourStatusEnum::cases(),
            'status' => $status,
            'name' => $name,
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<mixed>          $sent
     * @param array<string, string> $wrong
     */
    private function tourPage(Tour $tour, Request $request, array $sent = [], array $wrong = [], bool $expired = false): Response
    {
        $overnights = [];
        foreach ($tour->getDays() as $day) {
            $overnights[$day->getNumber()] = $this->service->overnightName($day);
        }

        return new Response($this->twig->render('@VivutioTouring/tours/show.html.twig', [
            'tour' => $tour,
            'overnights' => $overnights,
            'names' => $this->destinationNames(),
            'quote' => $this->quote($tour, $request),
            'asked' => [
                'start' => $request->query->getString('start'),
                'adults' => $request->query->getString('adults', '2'),
                'children' => $request->query->getString('children', '0'),
                'residency' => $request->query->getString('residency', ResidencyEnum::NonResident->value),
            ],
            'residencies' => ResidencyEnum::cases(),
            ...$this->dayForm($sent),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
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
     * @param array<mixed>          $sent
     * @param array<string, string> $wrong
     */
    private function dayPage(TourDay $day, array $sent, array $wrong = [], bool $expired = false): Response
    {
        return new Response($this->twig->render('@VivutioTouring/tours/day_configure.html.twig', [
            'tour' => $day->getTour(),
            'day' => $day,
            ...$this->dayForm($sent),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * What the day's form needs, with what was typed.
     *
     * @param array<mixed> $sent
     *
     * @return array<string, mixed>
     */
    private function dayForm(array $sent): array
    {
        $byCountry = [];
        foreach ($this->destinations->findBy([], ['name' => 'ASC']) as $destination) {
            $byCountry[Countries::getName($destination->getCountry())][] = $destination;
        }
        ksort($byCountry);
        $text = static fn (string $key): string => \is_string($sent[$key] ?? null) ? $sent[$key] : '';
        $destinations = \is_array($sent['destinations'] ?? null) ? $sent['destinations'] : [];
        $meals = \is_array($sent['meals'] ?? null) ? $sent['meals'] : [];

        return [
            'destination_choices' => $byCountry,
            'overnight_choices' => $this->service->overnightChoices(),
            'meals' => MealEnum::cases(),
            'typed' => [
                'title' => $text('title'),
                'destinations' => array_map(static fn (int $i): string => \is_string($destinations[$i] ?? null) ? $destinations[$i] : '', range(0, TourDay::MOST_DESTINATIONS - 1)),
                'overnight' => $text('overnight'),
                'meals' => array_keys(array_filter($meals)),
                'description' => $text('description'),
                'distance_km' => $text('distance_km'),
                'drive_hours' => $text('drive_hours'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function typedDay(TourDay $day): array
    {
        return [
            'title' => $day->getTitle(),
            'destinations' => $day->getDestinations(),
            'overnight' => null === $day->getOvernightKind() ? '' : $day->getOvernightKind().':'.$day->getOvernightId(),
            'meals' => array_fill_keys($day->getMeals(), '1'),
            'description' => $day->getDescription(),
            'distance_km' => null === $day->getDistanceKm() ? '' : (string) $day->getDistanceKm(),
            'drive_hours' => $day->getDriveHours() ?? '',
        ];
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
