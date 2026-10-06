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

namespace Vivutio\Touring\Service;

use Doctrine\ORM\EntityManagerInterface;
use Vivutio\Touring\Entity\TourSeason;
use Vivutio\Touring\Enum\SeasonToneEnum;
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Repository\TourSeasonRepository;

/**
 * The organization's tour seasons, the same every year. Each runs over spans
 * of days and months, which never overlap another season's; one may be the
 * season of every day no span names, so a date is never without a season.
 */
final readonly class TourSeasonService
{
    public const int MOST_PERIODS = 6;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TourSeasonRepository $seasons,
    ) {
    }

    /**
     * @return list<TourSeason>
     */
    public function seasons(): array
    {
        return $this->seasons->findBy([], ['tone' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * @throws InvalidTourException
     */
    public function create(string $name, string $tone): TourSeason
    {
        $season = new TourSeason($this->name($name, null), self::tone($tone));
        $this->entityManager->persist($season);
        $this->entityManager->flush();

        return $season;
    }

    /**
     * @param array<mixed> $sent name, tone, rest, periods[i][from|to]
     *
     * @throws InvalidTourException
     */
    public function configure(TourSeason $season, array $sent): void
    {
        $text = static fn (string $key): string => \is_string($sent[$key] ?? null) ? $sent[$key] : '';
        $name = $this->name($text('name'), $season);
        $tone = self::tone($text('tone'));
        $rest = '' !== $text('rest');

        $periods = [];
        $typed = \is_array($sent['periods'] ?? null) ? array_values($sent['periods']) : [];
        foreach ($typed as $i => $period) {
            $period = \is_array($period) ? $period : [];
            $fromTyped = \is_string($period['from'] ?? null) ? trim($period['from']) : '';
            $toTyped = \is_string($period['to'] ?? null) ? trim($period['to']) : '';
            if ('' === $fromTyped && '' === $toTyped) {
                continue;
            }
            $from = self::dayOfYear($fromTyped) ?? throw new InvalidTourException(\sprintf('periods[%d][from]', $i), 'A day of the year: 1 Apr, or 04-01.');
            $to = self::dayOfYear($toTyped) ?? throw new InvalidTourException(\sprintf('periods[%d][to]', $i), 'A day of the year: 19 May, or 05-19.');
            foreach ($this->seasons->findAll() as $other) {
                if ($other === $season) {
                    continue;
                }
                foreach ($other->getPeriods() as $theirs) {
                    if ([] !== array_intersect(self::days($from, $to), self::days($theirs['from'], $theirs['to']))) {
                        throw new InvalidTourException(\sprintf('periods[%d][from]', $i), \sprintf('%s runs %s already.', $other->getName(), self::span($theirs)));
                    }
                }
            }
            foreach ($periods as $mine) {
                if ([] !== array_intersect(self::days($from, $to), self::days($mine['from'], $mine['to']))) {
                    throw new InvalidTourException(\sprintf('periods[%d][from]', $i), 'This span overlaps another of the season.');
                }
            }
            $periods[] = ['from' => $from, 'to' => $to];
        }
        if (\count($periods) > self::MOST_PERIODS) {
            throw new InvalidTourException('periods[0][from]', \sprintf('A season runs in at most %d spans.', self::MOST_PERIODS));
        }

        if ($rest) {
            foreach ($this->seasons->findBy(['forTheRest' => true]) as $other) {
                $other->setForTheRest(false);
            }
        }
        $season->setName($name)->setTone($tone)->setPeriods($periods)->setForTheRest($rest);
        $this->entityManager->flush();
    }

    public function remove(TourSeason $season): void
    {
        $this->entityManager->remove($season);
        $this->entityManager->flush();
    }

    /** The season of a day: the one whose span names it, or the one for every other day. */
    public function seasonOn(\DateTimeImmutable $day): ?TourSeason
    {
        $rest = null;
        foreach ($this->seasons() as $season) {
            if ($season->runsOn($day)) {
                return $season;
            }
            if ($season->isForTheRest()) {
                $rest = $season;
            }
        }

        return $rest;
    }

    /**
     * A year as months of weeks, each day with its season.
     *
     * @return list<array{name: string, weeks: list<list<array{date: \DateTimeImmutable, season: ?TourSeason}|null>>}>
     */
    public function calendar(int $year): array
    {
        $months = [];
        for ($m = 1; $m <= 12; ++$m) {
            $first = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $m));
            $weeks = [];
            $week = array_fill(0, (int) $first->format('N') - 1, null);
            for ($day = $first; (int) $day->format('n') === $m; $day = $day->modify('+1 day')) {
                $week[] = ['date' => $day, 'season' => $this->seasonOn($day)];
                if (7 === \count($week)) {
                    $weeks[] = $week;
                    $week = [];
                }
            }
            if ([] !== $week) {
                $weeks[] = array_pad($week, 7, null);
            }
            $months[] = ['name' => $first->format('F'), 'weeks' => $weeks];
        }

        return $months;
    }

    /** What a season runs: "1 Apr – 19 May, 20 Dec – 10 Jan", "Every other day". */
    public function describe(TourSeason $season): string
    {
        $spans = implode(', ', array_map(self::span(...), $season->getPeriods()));

        return match (true) {
            '' === $spans && $season->isForTheRest() => 'Every other day',
            '' === $spans => 'No span yet',
            $season->isForTheRest() => $spans.', and every other day',
            default => $spans,
        };
    }

    /**
     * @param array{from: string, to: string} $period
     */
    public static function span(array $period): string
    {
        return self::spoken($period['from']).' – '.self::spoken($period['to']);
    }

    /** "04-01" as "1 Apr". */
    public static function spoken(string $md): string
    {
        return (new \DateTimeImmutable('2001-'.$md))->format('j M');
    }

    /** A day of the year as typed, "1 Apr", "1 April" or "04-01", as "04-01"; null when it is none. */
    private static function dayOfYear(string $typed): ?string
    {
        if (1 === preg_match('{^(\d{2})-(\d{2})$}D', $typed, $md)) {
            $m = (int) $md[1];
            $d = (int) $md[2];
        } elseif (1 === preg_match('{^(\d{1,2})\s+([A-Za-z]+)$}D', $typed, $dm)) {
            $parsed = \DateTimeImmutable::createFromFormat('!M Y', mb_substr($dm[2], 0, 3).' 2001');
            if (false === $parsed || \DateTimeImmutable::getLastErrors()) {
                return null;
            }
            $m = (int) $parsed->format('n');
            $d = (int) $dm[1];
        } else {
            return null;
        }

        return checkdate($m, $d, 2001) ? \sprintf('%02d-%02d', $m, $d) : null;
    }

    /**
     * The days a span takes, as "MM-DD", in a year of 365 days.
     *
     * @return list<string>
     */
    private static function days(string $from, string $to): array
    {
        $days = [];
        $day = new \DateTimeImmutable('2001-'.$from);
        $end = new \DateTimeImmutable(('2001-'.$to) < ('2001-'.$from) ? '2002-'.$to : '2001-'.$to);
        for (; $day <= $end; $day = $day->modify('+1 day')) {
            $days[] = $day->format('m-d');
        }

        return $days;
    }

    /**
     * @throws InvalidTourException
     */
    private function name(string $name, ?TourSeason $self): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > TourSeason::NAME_MAX_LENGTH) {
            throw new InvalidTourException('name', \sprintf('A season is known by its name, up to %d characters.', TourSeason::NAME_MAX_LENGTH));
        }
        foreach ($this->seasons->findAll() as $other) {
            if ($other !== $self && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                throw new InvalidTourException('name', \sprintf('There is a season called %s already.', $other->getName()));
            }
        }

        return $name;
    }

    /**
     * @throws InvalidTourException
     */
    private static function tone(string $typed): SeasonToneEnum
    {
        return ctype_digit($typed) ? (SeasonToneEnum::tryFrom((int) $typed) ?? throw new InvalidTourException('tone', 'Choose how busy it is.')) : throw new InvalidTourException('tone', 'Choose how busy it is.');
    }
}
