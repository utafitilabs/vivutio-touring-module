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
use Vivutio\Touring\Repository\TourRateRepository;

/**
 * A tour's price per person for one season, one tier (by its place in the
 * tour's tiers) and one group size bracket (from min to max people).
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: TourRateRepository::class)]
#[ORM\Table(name: 'touring_rate')]
#[ORM\UniqueConstraint(columns: ['tour_id', 'season_id', 'tier', 'min_people'])]
class TourRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tour $tour,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private TourSeason $season,
        #[ORM\Column]
        private int $tier,
        #[ORM\Column]
        private int $minPeople,
        #[ORM\Column]
        private int $maxPeople,
        #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
        private string $amount,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTour(): Tour
    {
        return $this->tour;
    }

    public function getSeason(): TourSeason
    {
        return $this->season;
    }

    public function getTier(): int
    {
        return $this->tier;
    }

    public function getMinPeople(): int
    {
        return $this->minPeople;
    }

    public function getMaxPeople(): int
    {
        return $this->maxPeople;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }
}
