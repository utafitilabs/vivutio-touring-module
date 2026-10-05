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
 * A day of a tour: its number, what it is called, the destinations it goes
 * to by the keys the core knows them by, where its night is spent (a place a
 * package offers, or an accommodation partner, by kind and id), the meals it
 * includes, what happens, how far it drives and for how long.
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

    /** @var list<string> the destinations' keys, "tz-serengeti" */
    #[ORM\Column(type: Types::JSON)]
    private array $destinations = [];

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $overnightKind = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $overnightId = null;

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

    public function getOvernightKind(): ?string
    {
        return $this->overnightKind;
    }

    public function getOvernightId(): ?string
    {
        return $this->overnightId;
    }

    public function setOvernight(?string $kind, ?string $id): static
    {
        $this->overnightKind = $kind;
        $this->overnightId = $id;

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
