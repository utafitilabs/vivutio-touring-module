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
use Vivutio\Bundle\IdentityBundle\Entity\Office;
use Vivutio\Bundle\IdentityBundle\Service\PlaceDirectoryService;
use Vivutio\Bundle\PlaceBundle\Repository\DestinationRepository;
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourDay;
use Vivutio\Touring\Enum\MealEnum;
use Vivutio\Touring\Enum\TourStatusEnum;
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Repository\TourRepository;

/**
 * Tours and their days. A day goes to destinations the core knows, and its
 * night is spent at a place a package offers (an office is not somewhere
 * guests sleep) or at an accommodation partner the organization trades with
 * now. A tour opens for sale with at least one day.
 */
final readonly class TourService
{
    public const string PARTNER_KIND = 'partner';
    public const int GROUP_MAX = 60;
    public const int LONGEST_DRIVE = 16;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TourRepository $tours,
        private DestinationRepository $destinations,
        private PlaceDirectoryService $places,
        private PartnerDirectoryInterface $partners,
    ) {
    }

    /**
     * @throws InvalidTourException
     */
    public function create(string $name): Tour
    {
        $tour = new Tour($this->name($name, null));
        $this->entityManager->persist($tour);
        $this->entityManager->flush();

        return $tour;
    }

    /**
     * @param array<string, string> $typed name, summary, group_min, group_max, included, excluded
     *
     * @throws InvalidTourException
     */
    public function configure(Tour $tour, array $typed): void
    {
        $name = $this->name($typed['name'] ?? '', $tour);
        $summary = trim($typed['summary'] ?? '');
        if (mb_strlen($summary) > Tour::SUMMARY_MAX_LENGTH) {
            throw new InvalidTourException('summary', \sprintf('A summary can be at most %d characters.', Tour::SUMMARY_MAX_LENGTH));
        }
        $min = self::whole($typed['group_min'] ?? '', 'group_min', 1, self::GROUP_MAX, \sprintf('A group is from 1 to %d people.', self::GROUP_MAX));
        $max = self::whole($typed['group_max'] ?? '', 'group_max', 1, self::GROUP_MAX, \sprintf('A group is from 1 to %d people.', self::GROUP_MAX));
        if ($max < $min) {
            throw new InvalidTourException('group_max', 'The largest group is at least the smallest.');
        }

        $tour->setName($name)
            ->setSummary($summary)
            ->setGroup($min, $max)
            ->setIncluded(self::lines($typed['included'] ?? ''))
            ->setExcluded(self::lines($typed['excluded'] ?? ''));
        $this->entityManager->flush();
    }

    /**
     * @throws InvalidTourException
     */
    public function open(Tour $tour): void
    {
        if ($tour->getDays()->isEmpty()) {
            throw new InvalidTourException('status', 'A tour opens with at least one day: write its first day.');
        }
        $tour->setStatus(TourStatusEnum::Open);
        $this->entityManager->flush();
    }

    public function archive(Tour $tour): void
    {
        $tour->setStatus(TourStatusEnum::Archived);
        $this->entityManager->flush();
    }

    /**
     * @param array<mixed> $sent the day's form
     *
     * @throws InvalidTourException
     */
    public function addDay(Tour $tour, array $sent): TourDay
    {
        $day = new TourDay($tour, $tour->getDays()->count() + 1);
        $this->write($day, $sent);
        $tour->getDays()->add($day);
        $this->entityManager->persist($day);
        $this->entityManager->flush();

        return $day;
    }

    /**
     * @param array<mixed> $sent the day's form
     *
     * @throws InvalidTourException
     */
    public function changeDay(TourDay $day, array $sent): void
    {
        $this->write($day, $sent);
        $this->entityManager->flush();
    }

    /** The day goes, and the days after it move up one. */
    public function removeDay(TourDay $day): void
    {
        $tour = $day->getTour();
        $tour->getDays()->removeElement($day);
        $this->entityManager->remove($day);
        $number = 1;
        foreach ($tour->getDays() as $other) {
            $other->setNumber($number++);
        }
        $this->entityManager->flush();
    }

    /**
     * Where a night can be spent, for the day's form: each package's places
     * but offices, then the accommodation partners traded with now.
     *
     * @return list<array{label: string, options: list<array{value: string, name: string}>}>
     */
    public function overnightChoices(): array
    {
        $groups = [];
        foreach ($this->places->groups() as $group) {
            if (Office::PLACE_KIND === $group->kind) {
                continue;
            }
            $options = [];
            foreach ($group->places as $place) {
                $options[] = ['value' => $place->getPlaceKind().':'.$place->getPlaceId(), 'name' => $place->getName()];
            }
            if ([] !== $options) {
                $groups[] = ['label' => $group->label, 'options' => $options];
            }
        }
        $partners = [];
        foreach ($this->partners->active() as $partner) {
            if ('accommodation' === $partner->getPartnerKind()) {
                $partners[] = ['value' => self::PARTNER_KIND.':'.$partner->getPartnerId(), 'name' => $partner->getName()];
            }
        }
        if ([] !== $partners) {
            $groups[] = ['label' => 'Accommodation partners', 'options' => $partners];
        }

        return $groups;
    }

    /** The name of where a day's night is spent, or null when it is not set. */
    public function overnightName(TourDay $day): ?string
    {
        $kind = $day->getOvernightKind();
        $id = $day->getOvernightId();
        if (null === $kind || null === $id) {
            return null;
        }
        if (self::PARTNER_KIND === $kind) {
            return $this->partners->find($id)?->getName() ?? 'A partner no longer kept';
        }

        return $this->places->nameOf($kind, $id) ?? 'A place no longer offered';
    }

    /**
     * @param array<mixed> $sent
     *
     * @throws InvalidTourException
     */
    private function write(TourDay $day, array $sent): void
    {
        $text = static fn (string $key): string => \is_string($sent[$key] ?? null) ? trim($sent[$key]) : '';

        $title = $text('title');
        if ('' === $title) {
            throw new InvalidTourException('title', 'A day is known by what it is called: Arusha to Tarangire.');
        }
        if (mb_strlen($title) > TourDay::TITLE_MAX_LENGTH) {
            throw new InvalidTourException('title', \sprintf('A title can be at most %d characters.', TourDay::TITLE_MAX_LENGTH));
        }

        $keys = [];
        $typed = \is_array($sent['destinations'] ?? null) ? $sent['destinations'] : [];
        for ($i = 0; $i < TourDay::MOST_DESTINATIONS; ++$i) {
            $key = \is_string($typed[$i] ?? null) ? trim($typed[$i]) : '';
            if ('' === $key || \in_array($key, $keys, true)) {
                continue;
            }
            if (null === $this->destinations->findOneBy(['key' => $key])) {
                throw new InvalidTourException(\sprintf('destinations[%d]', $i), 'Choose a destination from the list.');
            }
            $keys[] = $key;
        }

        [$kind, $id] = $this->overnight($text('overnight'));

        $meals = [];
        $ticked = \is_array($sent['meals'] ?? null) ? $sent['meals'] : [];
        foreach (MealEnum::cases() as $meal) {
            if (isset($ticked[$meal->value])) {
                $meals[] = $meal->value;
            }
        }

        $description = $text('description');
        if (mb_strlen($description) > TourDay::DESCRIPTION_MAX_LENGTH) {
            throw new InvalidTourException('description', \sprintf('A description can be at most %d characters.', TourDay::DESCRIPTION_MAX_LENGTH));
        }

        $distance = $text('distance_km');
        if ('' !== $distance && (!ctype_digit($distance) || (int) $distance > 2000)) {
            throw new InvalidTourException('distance_km', 'A distance is whole kilometres, up to 2,000.');
        }
        $hours = $text('drive_hours');
        if ('' !== $hours && (1 !== preg_match('{^\d{1,2}(\.\d)?$}D', $hours) || (float) $hours > self::LONGEST_DRIVE)) {
            throw new InvalidTourException('drive_hours', \sprintf('A drive is hours to the tenth, up to %d: 2.5.', self::LONGEST_DRIVE));
        }

        $day->setTitle($title)
            ->setDestinations($keys)
            ->setOvernight($kind, $id)
            ->setMeals($meals)
            ->setDescription($description)
            ->setDrive('' === $distance ? null : (int) $distance, '' === $hours ? null : number_format((float) $hours, 1, '.', ''));
    }

    /**
     * @return array{?string, ?string}
     *
     * @throws InvalidTourException
     */
    private function overnight(string $typed): array
    {
        if ('' === $typed) {
            return [null, null];
        }
        [$kind, $id] = array_pad(explode(':', $typed, 2), 2, '');
        if (self::PARTNER_KIND === $kind) {
            $partner = $this->partners->find($id);
            if (null !== $partner && $partner->isActive() && 'accommodation' === $partner->getPartnerKind()) {
                return [$kind, $partner->getPartnerId()];
            }
        } elseif (Office::PLACE_KIND !== $kind && null !== ($place = $this->places->find($kind, $id))) {
            return [$place->getPlaceKind(), $place->getPlaceId()];
        }

        throw new InvalidTourException('overnight', 'Choose where the night is spent from the list, or leave it not set.');
    }

    /**
     * @throws InvalidTourException
     */
    private function name(string $name, ?Tour $self): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw new InvalidTourException('name', 'A tour is known by its name: it cannot be empty.');
        }
        if (mb_strlen($name) > Tour::NAME_MAX_LENGTH) {
            throw new InvalidTourException('name', \sprintf('A name can be at most %d characters.', Tour::NAME_MAX_LENGTH));
        }
        foreach ($this->tours->findAll() as $other) {
            if ($other !== $self && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                throw new InvalidTourException('name', \sprintf('There is a tour called %s already.', $other->getName()));
            }
        }

        return $name;
    }

    /**
     * @throws InvalidTourException
     */
    private static function whole(string $typed, string $field, int $least, int $most, string $message): int
    {
        $typed = trim($typed);
        if (!ctype_digit($typed) || (int) $typed < $least || (int) $typed > $most) {
            throw new InvalidTourException($field, $message);
        }

        return (int) $typed;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $typed): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/\R/', $typed) ?: []), static fn (string $line): bool => '' !== $line));
    }
}
