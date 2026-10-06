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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Vivutio\Touring\Enum\TourStatusEnum;
use Vivutio\Touring\Repository\TourRepository;

/**
 * A tour: its name, what it is in a sentence, the group it is sized for, what
 * it includes and leaves out, and its days in order.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: TourRepository::class)]
#[ORM\Table(name: 'touring_tour')]
class Tour
{
    public const int NAME_MAX_LENGTH = 120;
    public const int SUMMARY_MAX_LENGTH = 600;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: self::NAME_MAX_LENGTH, unique: true)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $summary = '';

    #[ORM\Column]
    private int $groupMin = 1;

    #[ORM\Column]
    private int $groupMax = 6;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $included = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $excluded = [];

    /** @var list<string> the lodging tiers it is sold in, cheapest first; none is one lodging a night */
    #[ORM\Column(type: Types::JSON)]
    private array $tiers = [];

    /** The currency its prices are in, "USD"; empty until its prices are set. */
    #[ORM\Column(length: 3)]
    private string $priceCurrency = '';

    /** @var list<array{int, int}> the group sizes priced apart, each from min to max people */
    #[ORM\Column(type: Types::JSON)]
    private array $brackets = [];

    /** @var list<array{days: int, percent: int}>|null its own cancellation tiers; null follows the tours' terms, none charges nothing */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $cancellation = null;

    /** The margin wanted on its prices, a share to the cent: "20.00". */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $margin = '0.00';

    /** @var list<array{name: string, per: string, amount: int}> what the lodges and parks do not charge, in cents; per "group" or "person" */
    #[ORM\Column(type: Types::JSON)]
    private array $costs = [];

    #[ORM\Column(length: 16, enumType: TourStatusEnum::class)]
    private TourStatusEnum $status = TourStatusEnum::Draft;

    /** @var Collection<int, TourDay> */
    #[ORM\OneToMany(targetEntity: TourDay::class, mappedBy: 'tour')]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $days;

    public function __construct(string $name)
    {
        $this->uuid = Uuid::v7();
        $this->name = $name;
        $this->days = new ArrayCollection();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getGroupMin(): int
    {
        return $this->groupMin;
    }

    public function getGroupMax(): int
    {
        return $this->groupMax;
    }

    public function setGroup(int $min, int $max): static
    {
        $this->groupMin = $min;
        $this->groupMax = $max;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getIncluded(): array
    {
        return $this->included;
    }

    /**
     * @param list<string> $included
     */
    public function setIncluded(array $included): static
    {
        $this->included = $included;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getExcluded(): array
    {
        return $this->excluded;
    }

    /**
     * @param list<string> $excluded
     */
    public function setExcluded(array $excluded): static
    {
        $this->excluded = $excluded;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getTiers(): array
    {
        return $this->tiers;
    }

    /**
     * @param list<string> $tiers
     */
    public function setTiers(array $tiers): static
    {
        $this->tiers = $tiers;

        return $this;
    }

    public function getPriceCurrency(): string
    {
        return $this->priceCurrency;
    }

    /**
     * @return list<array{int, int}>
     */
    public function getBrackets(): array
    {
        return $this->brackets;
    }

    /**
     * @param list<array{int, int}> $brackets
     */
    public function setPricing(string $currency, array $brackets): static
    {
        $this->priceCurrency = $currency;
        $this->brackets = $brackets;

        return $this;
    }

    /**
     * @return list<array{days: int, percent: int}>|null
     */
    public function getCancellation(): ?array
    {
        return $this->cancellation;
    }

    /**
     * @param list<array{days: int, percent: int}>|null $cancellation
     */
    public function setCancellation(?array $cancellation): static
    {
        $this->cancellation = $cancellation;

        return $this;
    }

    public function getMargin(): string
    {
        return $this->margin;
    }

    /**
     * @return list<array{name: string, per: string, amount: int}>
     */
    public function getCosts(): array
    {
        return $this->costs;
    }

    /**
     * @param list<array{name: string, per: string, amount: int}> $costs
     */
    public function setCosting(string $margin, array $costs): static
    {
        $this->margin = $margin;
        $this->costs = $costs;

        return $this;
    }

    public function getStatus(): TourStatusEnum
    {
        return $this->status;
    }

    public function setStatus(TourStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return Collection<int, TourDay>
     */
    public function getDays(): Collection
    {
        return $this->days;
    }
}
