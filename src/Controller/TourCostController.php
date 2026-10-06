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
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Service\TourCostService;

/**
 * A tour's Configure › Costs tab, as drawn (vivutio-designs
 * tours/cost-sheet, A): the margin wanted and the tour's own costs; each
 * price beside its cost a person, its margin and the price the margin calls
 * for, a cell broken down; a tier's prices taken over when asked. Kept with
 * tours.manage, as the tour's other Configure tabs.
 */
final readonly class TourCostController
{
    public const string COSTS = 'touring_tour_costs';
    public const string TAKE = 'touring_tour_costs_take';

    /** The empty rows below the costs kept, at the least three rows in all. */
    public const int FIRST_ROWS = 3;

    public function __construct(
        private Environment $twig,
        private TourCostService $costs,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/tours/{uuid}/costs', name: self::COSTS, requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(TourController::MANAGE)]
    public function costs(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Tour $tour,
    ): Response {
        $chosen = $request->query->getString('cell');
        if (!$request->isMethod('POST')) {
            return $this->costsPage($tour, $chosen, null);
        }
        $sent = $request->getPayload()->all();
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_costs', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->costsPage($tour, $chosen, $sent, expired: true);
        }

        try {
            $this->costs->save($tour, $sent);
        } catch (InvalidTourException $refusal) {
            return $this->costsPage($tour, $chosen, $sent, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::COSTS, ['uuid' => $tour->getUuid()]));
    }

    #[Route('/tours/{uuid}/costs/take', name: self::TAKE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TourController::MANAGE)]
    public function take(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Tour $tour,
    ): Response {
        $payload = $request->getPayload();
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_costs_take', $payload->getString('_token')))) {
            return $this->costsPage($tour, '', null, expired: true);
        }
        $tier = $payload->getString('tier');

        try {
            $this->costs->takeOver($tour, ctype_digit($tier) ? (int) $tier : -1);
        } catch (InvalidTourException $refusal) {
            return $this->costsPage($tour, '', null, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::COSTS, ['uuid' => $tour->getUuid()]));
    }

    /**
     * @param array<mixed>|null     $sent
     * @param array<string, string> $wrong
     */
    private function costsPage(Tour $tour, string $chosen, ?array $sent, array $wrong = [], bool $expired = false): Response
    {
        $sheet = $this->costs->sheet($tour);
        $cells = [];
        foreach ($sheet as $tier) {
            foreach ($tier['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    $cells[] = $cell;
                }
            }
        }
        $picked = null;
        foreach ($cells as $cell) {
            if ($cell['key'] === $chosen) {
                $picked = $cell;
            }
        }
        $picked ??= array_values(array_filter($cells, static fn (array $cell): bool => null !== $cell['price'] && null !== $cell['cost']))[0] ?? ($cells[0] ?? null);

        $rows = null === $sent
            ? array_map(static fn (array $own): array => ['name' => $own['name'], 'per' => $own['per'], 'amount' => number_format($own['amount'] / 100, 2, '.', '')], $tour->getCosts())
            : (\is_array($sent['costs'] ?? null) ? array_values(array_filter($sent['costs'], 'is_array')) : []);
        $margin = null === $sent ? rtrim(rtrim($tour->getMargin(), '0'), '.') : (\is_string($sent['margin'] ?? null) ? $sent['margin'] : '');

        return new Response($this->twig->render('@VivutioTouring/tours/costs.html.twig', [
            'tour' => $tour,
            'sheet' => $sheet,
            'picked' => $picked,
            'margin' => '' === $margin ? '0' : $margin,
            'wanted' => (float) $tour->getMargin(),
            'wanted_says' => rtrim(rtrim($tour->getMargin(), '0'), '.') ?: '0',
            'rows' => array_pad($rows, max(\count($rows) + 2, self::FIRST_ROWS), ['name' => '', 'per' => TourCostService::PER_GROUP, 'amount' => '']),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
