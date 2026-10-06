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
use Vivutio\Touring\Entity\TourSeason;
use Vivutio\Touring\Enum\SeasonToneEnum;
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Service\TourSeasonService;

/**
 * The organization's tour seasons, as drawn (vivutio-designs tours/seasons):
 * the year coloured by season, the seasons, adding one, and a season's
 * Configure page with its spans. Read with tours.read, kept with tours.manage.
 */
final readonly class SeasonController
{
    public const string SEASONS = 'touring_seasons';
    public const string ADD = 'touring_season_add';
    public const string CONFIGURE = 'touring_season_configure';
    public const string REMOVE = 'touring_season_remove';

    public function __construct(
        private Environment $twig,
        private TourSeasonService $seasons,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/tours/seasons', name: self::SEASONS, methods: ['GET'])]
    #[IsGranted(TourController::READ)]
    public function seasons(Request $request): Response
    {
        $year = $request->query->getString('year');

        return $this->seasonsPage(ctype_digit($year) && (int) $year >= 2000 && (int) $year <= 2100 ? (int) $year : (int) date('Y'));
    }

    #[Route('/tours/seasons', name: self::ADD, methods: ['POST'])]
    #[IsGranted(TourController::MANAGE)]
    public function add(Request $request): Response
    {
        $payload = $request->getPayload();
        $typed = ['name' => $payload->getString('name'), 'tone' => $payload->getString('tone')];
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_season_add', $payload->getString('_token')))) {
            return $this->seasonsPage((int) date('Y'), $typed, expired: true);
        }

        try {
            $season = $this->seasons->create($typed['name'], $typed['tone']);
        } catch (InvalidTourException $refusal) {
            return $this->seasonsPage((int) date('Y'), $typed, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::CONFIGURE, ['uuid' => $season->getUuid()]));
    }

    #[Route('/tours/seasons/{uuid}/configure', name: self::CONFIGURE, requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(TourController::MANAGE)]
    public function configure(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        TourSeason $season,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->configurePage($season, [
                'name' => $season->getName(),
                'tone' => (string) $season->getTone()->value,
                'rest' => $season->isForTheRest() ? '1' : '',
                'periods' => array_map(static fn (array $p): array => ['from' => TourSeasonService::spoken($p['from']), 'to' => TourSeasonService::spoken($p['to'])], $season->getPeriods()),
            ]);
        }

        $sent = $request->getPayload()->all();
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_season_configure', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->configurePage($season, $sent, expired: true);
        }

        try {
            $this->seasons->configure($season, $sent);
        } catch (InvalidTourException $refusal) {
            return $this->configurePage($season, $sent, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::SEASONS));
    }

    #[Route('/tours/seasons/{uuid}/remove', name: self::REMOVE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TourController::MANAGE)]
    public function remove(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        TourSeason $season,
    ): Response {
        if ($this->tokens->isTokenValid(new CsrfToken('touring_season_remove', $request->getPayload()->getString('_token')))) {
            $this->seasons->remove($season);
        }

        return new RedirectResponse($this->urls->generate(self::SEASONS));
    }

    /**
     * @param array<string, string> $typed
     * @param array<string, string> $wrong
     */
    private function seasonsPage(int $year, array $typed = [], array $wrong = [], bool $expired = false): Response
    {
        $seasons = $this->seasons->seasons();

        return new Response($this->twig->render('@VivutioTouring/seasons/index.html.twig', [
            'year' => $year,
            'seasons' => $seasons,
            'runs' => array_combine(array_map(static fn (TourSeason $s): string => (string) $s->getUuid(), $seasons), array_map($this->seasons->describe(...), $seasons)),
            'calendar' => $this->seasons->calendar($year),
            'tones' => SeasonToneEnum::cases(),
            'typed' => [...['name' => '', 'tone' => (string) SeasonToneEnum::Busy->value], ...$typed],
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<mixed>          $typed
     * @param array<string, string> $wrong
     */
    private function configurePage(TourSeason $season, array $typed, array $wrong = [], bool $expired = false): Response
    {
        $periods = \is_array($typed['periods'] ?? null) ? array_values($typed['periods']) : [];
        $periods = array_pad($periods, max(\count($periods) + 1, 2), ['from' => '', 'to' => '']);

        return new Response($this->twig->render('@VivutioTouring/seasons/configure.html.twig', [
            'season' => $season,
            'typed' => $typed,
            'periods' => \array_slice($periods, 0, TourSeasonService::MOST_PERIODS),
            'tones' => SeasonToneEnum::cases(),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
