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

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Service\TourCancellationService;

/**
 * The tours' cancellation terms, as drawn (vivutio-designs
 * tours/cancellation): read with tours.read, kept with tours.manage.
 */
final readonly class TourCancellationController
{
    public const string TERMS = 'touring_cancellation';

    /** The empty rows below the tiers kept, at the least five rows in all. */
    public const int FIRST_ROWS = 5;

    public function __construct(
        private Environment $twig,
        private TourCancellationService $cancellation,
        private AuthorizationCheckerInterface $authorization,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/tours/cancellation', name: self::TERMS, methods: ['GET', 'POST'])]
    #[IsGranted(TourController::READ)]
    public function terms(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->termsPage(array_map(static fn (array $t): array => [(string) $t['days'], (string) $t['percent']], $this->cancellation->terms()));
        }
        if (!$this->authorization->isGranted(TourController::MANAGE)) {
            throw new AccessDeniedHttpException('Keeping the tours’ terms is not allowed.');
        }
        $sent = $request->getPayload()->all();
        $rows = self::rows($sent);
        if (!$this->tokens->isTokenValid(new CsrfToken('touring_cancellation', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->termsPage($rows, expired: true);
        }

        try {
            $this->cancellation->saveTerms($rows);
        } catch (InvalidTourException $refusal) {
            return $this->termsPage($rows, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::TERMS));
    }

    /**
     * The tier rows as typed, days and per cent each.
     *
     * @param array<mixed> $sent
     *
     * @return list<array{string, string}>
     */
    public static function rows(array $sent): array
    {
        $rows = [];
        foreach (\is_array($sent['tiers'] ?? null) ? $sent['tiers'] : [] as $row) {
            $row = \is_array($row) ? $row : [];
            $rows[] = [\is_string($row['days'] ?? null) ? $row['days'] : '', \is_string($row['percent'] ?? null) ? $row['percent'] : ''];
        }

        return $rows;
    }

    /**
     * @param list<array{string, string}> $rows
     * @param array<string, string>       $wrong
     */
    private function termsPage(array $rows, array $wrong = [], bool $expired = false): Response
    {
        return new Response($this->twig->render('@VivutioTouring/tours/cancellation.html.twig', [
            'rows' => array_pad($rows, max(\count($rows) + 2, self::FIRST_ROWS), ['', '']),
            'bands' => TourCancellationService::bands($this->cancellation->terms()),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
