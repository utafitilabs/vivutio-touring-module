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

namespace Vivutio\Touring\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Vivutio\Touring\Repository\TourDayRepository;

/**
 * A stay of a tour: its place in the itinerary, what it is called, its route
 * through the destinations by the keys the core knows them by, how many
 * nights it lasts (none for a last day), where each night is spent in each of
 * the tour's tiers (a place a package offers, or an accommodation partner, as
 * kind:id), the meals and activities it includes, what happens, how far it
 * drives and for how long.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: TourDayRepository::class)]
#[ORM\Table(name: 'touring_tour_day')]
class TourDay
{
    public const int TITLE_MAX_LENGTH = 120;
    public const int DESCRIPTION_MAX_LENGTH = 4000;
    public const int MOST_DESTINATIONS = 3;
    public const int ACTIVITIES_MAX_LENGTH = 240;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(inversedBy: 'days')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tour $tour;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(length: self::TITLE_MAX_LENGTH)]
    private string $title = '';

    /** @var list<string> the destinations' keys, "tz-serengeti-national-park" */
    #[ORM\Column(type: Types::JSON)]
    private array $destinations = [];

    #[ORM\Column]
    private int $nights = 1;

    /** @var list<string|null> where each night is spent, a place or partner as kind:id, one a tier in the tour's order */
    #[ORM\Column(type: Types::JSON)]
    private array $stays = [];

    #[ORM\Column(length: self::ACTIVITIES_MAX_LENGTH)]
    private string $activities = '';

    /** @var list<string> the activities it takes that its destinations charge for, as "destination key:activity" */
    #[ORM\Column(type: Types::JSON)]
    private array $takes = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $meals = [];

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(nullable: true)]
    private ?int $distanceKm = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 1, nullable: true)]
    private ?string $driveHours = null;

    public function __construct(Tour $tour, int $number)
    {
        $this->uuid = Uuid::v7();
        $this->tour = $tour;
        $this->number = $number;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function setUuid(Uuid $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getTour(): Tour
    {
        return $this->tour;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function setNumber(int $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getDestinations(): array
    {
        return $this->destinations;
    }

    /**
     * @param list<string> $destinations
     */
    public function setDestinations(array $destinations): static
    {
        $this->destinations = $destinations;

        return $this;
    }

    public function getNights(): int
    {
        return $this->nights;
    }

    public function setNights(int $nights): static
    {
        $this->nights = $nights;

        return $this;
    }

    /** The days it takes: its nights, and a last day with none takes one. */
    public function getLength(): int
    {
        return max(1, $this->nights);
    }

    /**
     * @return list<string|null>
     */
    public function getStays(): array
    {
        return $this->stays;
    }

    /**
     * @param list<string|null> $stays
     */
    public function setStays(array $stays): static
    {
        $this->stays = $stays;

        return $this;
    }

    public function getActivities(): string
    {
        return $this->activities;
    }

    /**
     * @return list<string>
     */
    public function getTakes(): array
    {
        return $this->takes;
    }

    /**
     * @param list<string> $takes
     */
    public function setTakes(array $takes): static
    {
        $this->takes = $takes;

        return $this;
    }

    public function setActivities(string $activities): static
    {
        $this->activities = $activities;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getMeals(): array
    {
        return $this->meals;
    }

    /**
     * @param list<string> $meals
     */
    public function setMeals(array $meals): static
    {
        $this->meals = $meals;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDistanceKm(): ?int
    {
        return $this->distanceKm;
    }

    public function getDriveHours(): ?string
    {
        return $this->driveHours;
    }

    public function setDrive(?int $distanceKm, ?string $driveHours): static
    {
        $this->distanceKm = $distanceKm;
        $this->driveHours = $driveHours;

        return $this;
    }
}
