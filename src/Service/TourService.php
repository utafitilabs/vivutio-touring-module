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
 * Tours and their itineraries. A tour is sold in up to four lodging tiers; its
 * stays go to destinations the core knows, last a number of nights, and spend
 * each night, in each tier, at a place a package offers (an office is not
 * somewhere guests sleep) or at an accommodation partner traded with now. The
 * itinerary is edited as a whole and saved at once. A tour opens for sale with
 * at least one stay.
 */
final readonly class TourService
{
    public const string PARTNER_KIND = 'partner';
    public const int GROUP_MAX = 60;
    public const int LONGEST_DRIVE = 16;
    public const int MOST_TIERS = 4;

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
     * The itinerary as typed on its page, every stay validated before
     * anything is kept, then the step asked for: "save", "add", or "up",
     * "down", "duplicate", "insert" or "remove" with a stay's position,
     * "up:2". Returns the position of the stay the step reached, to open.
     *
     * @param array<mixed> $sent the page's form
     *
     * @throws InvalidTourException
     */
    public function saveItinerary(Tour $tour, array $sent, string $step): ?int
    {
        $tiers = $this->tiers(\is_string($sent['tiers'] ?? null) ? $sent['tiers'] : '');
        $typed = \is_array($sent['days'] ?? null) ? array_values($sent['days']) : [];
        $drafts = [];
        foreach ($typed as $i => $day) {
            $drafts[] = $this->draft(\is_array($day) ? $day : [], $i, \count($tiers));
        }

        [$verb, $at] = array_pad(explode(':', $step, 2), 2, '');
        $at = ctype_digit($at) && (int) $at < \count($drafts) ? (int) $at : null;
        $blank = ['title' => '', 'destinations' => [], 'stays' => [], 'nights' => 1, 'meals' => [], 'activities' => '', 'description' => '', 'distance' => null, 'hours' => null];
        $open = null;
        switch (true) {
            case 'add' === $verb:
                $drafts[] = $blank;
                $open = \count($drafts) - 1;
                break;
            case 'up' === $verb && null !== $at && $at > 0:
                [$drafts[$at - 1], $drafts[$at]] = [$drafts[$at], $drafts[$at - 1]];
                $open = $at - 1;
                break;
            case 'down' === $verb && null !== $at && $at < \count($drafts) - 1:
                [$drafts[$at + 1], $drafts[$at]] = [$drafts[$at], $drafts[$at + 1]];
                $open = $at + 1;
                break;
            case 'duplicate' === $verb && null !== $at:
                array_splice($drafts, $at + 1, 0, [$drafts[$at]]);
                $open = $at + 1;
                break;
            case 'insert' === $verb && null !== $at:
                array_splice($drafts, $at + 1, 0, [$blank]);
                $open = $at + 1;
                break;
            case 'remove' === $verb && null !== $at:
                array_splice($drafts, $at, 1);
                $open = [] === $drafts ? null : max(0, $at - 1);
                break;
        }

        $tour->setTiers($tiers);
        $days = array_values($tour->getDays()->toArray());
        foreach ($drafts as $i => $draft) {
            $day = $days[$i] ?? null;
            if (null === $day) {
                $day = new TourDay($tour, $i + 1);
                $tour->getDays()->add($day);
                $this->entityManager->persist($day);
            }
            $day->setNumber($i + 1)
                ->setTitle($draft['title'])
                ->setDestinations($draft['destinations'])
                ->setStays($draft['stays'])
                ->setNights($draft['nights'])
                ->setMeals($draft['meals'])
                ->setActivities($draft['activities'])
                ->setDescription($draft['description'])
                ->setDrive($draft['distance'], $draft['hours']);
        }
        foreach (\array_slice($days, \count($drafts)) as $gone) {
            $tour->getDays()->removeElement($gone);
            $this->entityManager->remove($gone);
        }
        $this->entityManager->flush();

        return $open;
    }

    /**
     * Each stay's label and where it starts: "Day 1", "Days 2–3", and the
     * tour's length in days and nights.
     *
     * @return array{labels: array<int, string>, days: int, nights: int}
     */
    public function schedule(Tour $tour): array
    {
        $labels = [];
        $first = 1;
        $nights = 0;
        foreach ($tour->getDays() as $day) {
            $last = $first + $day->getLength() - 1;
            $labels[$day->getNumber()] = $first === $last ? 'Day '.$first : \sprintf('Days %d–%d', $first, $last);
            $first = $last + 1;
            $nights += $day->getNights();
        }

        return ['labels' => $labels, 'days' => $first - 1, 'nights' => $nights];
    }

    /** What a stay is called: its title, or its route when it has none. */
    public function titleOf(TourDay $day): string
    {
        if ('' !== $day->getTitle()) {
            return $day->getTitle();
        }
        $names = [];
        foreach ($day->getDestinations() as $key) {
            $names[] = $this->destinations->findOneBy(['key' => $key])?->getName() ?? $key;
        }

        return [] === $names ? 'A day not yet written' : implode(' → ', $names);
    }

    /**
     * Where each night of a stay is spent, by tier: "Silver" => "Mwangaza Lodge".
     *
     * @return array<string, string>
     */
    public function staysOf(TourDay $day): array
    {
        $tiers = $day->getTour()->getTiers();
        $named = [];
        foreach ($day->getStays() as $i => $stay) {
            if (null === $stay) {
                continue;
            }
            [$kind, $id] = array_pad(explode(':', $stay, 2), 2, '');
            $name = self::PARTNER_KIND === $kind
                ? ($this->partners->find($id)?->getName() ?? 'A partner no longer kept')
                : ($this->places->nameOf($kind, $id) ?? 'A place no longer offered');
            $named[$tiers[$i] ?? 'The night at'] = $name;
        }

        return $named;
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

    /**
     * One stay as typed, checked field by field; a refusal names the field
     * as the form does, "days[2][nights]".
     *
     * @param array<mixed> $day
     *
     * @return array{title: string, destinations: list<string>, stays: list<string|null>, nights: int, meals: list<string>, activities: string, description: string, distance: ?int, hours: ?string}
     *
     * @throws InvalidTourException
     */
    private function draft(array $day, int $i, int $tierCount): array
    {
        $field = static fn (string $name): string => \sprintf('days[%d][%s]', $i, $name);
        $text = static fn (string $key): string => \is_string($day[$key] ?? null) ? trim($day[$key]) : '';

        $title = $text('title');
        if (mb_strlen($title) > TourDay::TITLE_MAX_LENGTH) {
            throw new InvalidTourException($field('title'), \sprintf('A title can be at most %d characters.', TourDay::TITLE_MAX_LENGTH));
        }

        $keys = [];
        $typed = \is_array($day['destinations'] ?? null) ? $day['destinations'] : [];
        for ($d = 0; $d < TourDay::MOST_DESTINATIONS; ++$d) {
            $key = \is_string($typed[$d] ?? null) ? trim($typed[$d]) : '';
            if ('' === $key || \in_array($key, $keys, true)) {
                continue;
            }
            if (null === $this->destinations->findOneBy(['key' => $key])) {
                throw new InvalidTourException($field('destinations').'['.$d.']', 'Choose a destination from the list.');
            }
            $keys[] = $key;
        }

        $nights = '' === $text('nights') ? '1' : $text('nights');
        if (!ctype_digit($nights) || (int) $nights > 30) {
            throw new InvalidTourException($field('nights'), 'A stay is from 0 nights, a last day, to 30.');
        }

        $stays = [];
        $typedStays = \is_array($day['stays'] ?? null) ? $day['stays'] : [];
        for ($t = 0; $t < max(1, $tierCount); ++$t) {
            $stay = \is_string($typedStays[$t] ?? null) ? trim($typedStays[$t]) : '';
            $stays[] = '' === $stay ? null : $this->overnight($stay, $field('stays').'['.$t.']');
        }

        $meals = [];
        $ticked = \is_array($day['meals'] ?? null) ? $day['meals'] : [];
        foreach (MealEnum::cases() as $meal) {
            if (isset($ticked[$meal->value])) {
                $meals[] = $meal->value;
            }
        }

        $activities = $text('activities');
        if (mb_strlen($activities) > TourDay::ACTIVITIES_MAX_LENGTH) {
            throw new InvalidTourException($field('activities'), \sprintf('Activities can be at most %d characters.', TourDay::ACTIVITIES_MAX_LENGTH));
        }
        $description = $text('description');
        if (mb_strlen($description) > TourDay::DESCRIPTION_MAX_LENGTH) {
            throw new InvalidTourException($field('description'), \sprintf('A description can be at most %d characters.', TourDay::DESCRIPTION_MAX_LENGTH));
        }
        $distance = $text('distance_km');
        if ('' !== $distance && (!ctype_digit($distance) || (int) $distance > 2000)) {
            throw new InvalidTourException($field('distance_km'), 'A distance is whole kilometres, up to 2,000.');
        }
        $hours = $text('drive_hours');
        if ('' !== $hours && (1 !== preg_match('{^\d{1,2}(\.\d)?$}D', $hours) || (float) $hours > self::LONGEST_DRIVE)) {
            throw new InvalidTourException($field('drive_hours'), \sprintf('A drive is hours to the tenth, up to %d: 2.5.', self::LONGEST_DRIVE));
        }

        return [
            'title' => $title,
            'destinations' => $keys,
            'stays' => $stays,
            'nights' => (int) $nights,
            'meals' => $meals,
            'activities' => $activities,
            'description' => $description,
            'distance' => '' === $distance ? null : (int) $distance,
            'hours' => '' === $hours ? null : number_format((float) $hours, 1, '.', ''),
        ];
    }

    /**
     * The tiers as typed, "Silver, Gold, Platinum": each named once.
     *
     * @return list<string>
     *
     * @throws InvalidTourException
     */
    private function tiers(string $typed): array
    {
        $tiers = [];
        foreach (explode(',', $typed) as $tier) {
            $tier = trim($tier);
            if ('' === $tier) {
                continue;
            }
            if (mb_strlen($tier) > 40 || \in_array(mb_strtolower($tier), array_map(mb_strtolower(...), $tiers), true)) {
                throw new InvalidTourException('tiers', 'Name each tier once, cheapest first: Silver, Gold, Platinum.');
            }
            $tiers[] = $tier;
        }
        if (\count($tiers) > self::MOST_TIERS) {
            throw new InvalidTourException('tiers', \sprintf('A tour is sold in at most %d tiers.', self::MOST_TIERS));
        }

        return $tiers;
    }

    /**
     * A place a package offers but an office, or an accommodation partner
     * traded with now, as kind:id.
     *
     * @throws InvalidTourException
     */
    private function overnight(string $typed, string $field): string
    {
        [$kind, $id] = array_pad(explode(':', $typed, 2), 2, '');
        if (self::PARTNER_KIND === $kind) {
            $partner = $this->partners->find($id);
            if (null !== $partner && $partner->isActive() && 'accommodation' === $partner->getPartnerKind()) {
                return $kind.':'.$partner->getPartnerId();
            }
        } elseif (Office::PLACE_KIND !== $kind && null !== ($place = $this->places->find($kind, $id))) {
            return $place->getPlaceKind().':'.$place->getPlaceId();
        }

        throw new InvalidTourException($field, 'Choose where the night is spent from the list, or leave it not set.');
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
