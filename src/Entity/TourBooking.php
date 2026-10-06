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
use Vivutio\Touring\Enum\TourBookingStatusEnum;
use Vivutio\Touring\Repository\TourBookingRepository;

/**
 * A tour sold: who it is for and who made it, the day it starts, the party and
 * its tier, and the price it was sold at, kept as it was when it was made (the
 * tier and season by name, the group size, the price a person, the partner's
 * discount and the total, in cents), so a later change of the tour's prices or
 * the partner's terms never alters it. A booking on a departure takes a seat
 * for each of its party, at the departure's seat price.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: TourBookingRepository::class)]
#[ORM\Table(name: 'touring_booking')]
class TourBooking
{
    public const int GUEST_MAX_LENGTH = 120;
    public const int REFERENCE_MAX_LENGTH = 60;
    public const int REASON_MAX_LENGTH = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Tour $tour;

    /** The departure it takes seats on, when the tour leaves on set days. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?TourDeparture $departure = null;

    /** "NC-0001": the tour's initials and a count. */
    #[ORM\Column(length: 16, unique: true)]
    private string $reference;

    /** The lead guest, or the party. */
    #[ORM\Column(length: self::GUEST_MAX_LENGTH)]
    private string $guest;

    #[ORM\Column(length: self::GUEST_MAX_LENGTH, nullable: true)]
    private ?string $bookedBy = null;

    #[ORM\Column(length: self::REFERENCE_MAX_LENGTH, nullable: true)]
    private ?string $theirReference = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $start;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $adults;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $children;

    /** The party's residency, the core's word: what the parks will charge it. */
    #[ORM\Column(length: 16)]
    private string $residency;

    /** The tier by its place in the tour's tiers when it was made, and by name. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $tier;

    #[ORM\Column(length: 40)]
    private string $tierName;

    #[ORM\Column(length: TourSeason::NAME_MAX_LENGTH)]
    private string $seasonName;

    /** The group size it was priced for: "3 – 4 people". */
    #[ORM\Column(length: 20)]
    private string $size;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** The price a person, in cents. */
    #[ORM\Column]
    private int $each;

    /** The price for the party before the partner's discount, in cents. */
    #[ORM\Column]
    private int $gross;

    /** What it is sold for, in cents. */
    #[ORM\Column]
    private int $total;

    /** The partner it came through, by the id the core gave it. */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $partnerId = null;

    /** The partner's discount and the days it has to pay, as they were. */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $discount = '0.00';

    #[ORM\Column]
    private int $creditDays = 0;

    #[ORM\Column(length: 16, enumType: TourBookingStatusEnum::class)]
    private TourBookingStatusEnum $status = TourBookingStatusEnum::Provisional;

    /** A provisional booking is held to the end of this day. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $heldUntil = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $madeAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(length: self::REASON_MAX_LENGTH, nullable: true)]
    private ?string $cancellationReason = null;

    public function __construct(Tour $tour, string $reference, string $guest, \DateTimeImmutable $start, \DateTimeImmutable $madeAt)
    {
        $this->uuid = Uuid::v7();
        $this->tour = $tour;
        $this->reference = $reference;
        $this->guest = $guest;
        $this->start = $start;
        $this->madeAt = $madeAt;
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

    public function getDeparture(): ?TourDeparture
    {
        return $this->departure;
    }

    public function setDeparture(?TourDeparture $departure): static
    {
        $this->departure = $departure;

        return $this;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getGuest(): string
    {
        return $this->guest;
    }

    public function getBookedBy(): ?string
    {
        return $this->bookedBy;
    }

    public function setBookedBy(?string $bookedBy): static
    {
        $this->bookedBy = $bookedBy;

        return $this;
    }

    public function getTheirReference(): ?string
    {
        return $this->theirReference;
    }

    public function setTheirReference(?string $theirReference): static
    {
        $this->theirReference = $theirReference;

        return $this;
    }

    public function getStart(): \DateTimeImmutable
    {
        return $this->start;
    }

    public function getAdults(): int
    {
        return $this->adults;
    }

    public function getChildren(): int
    {
        return $this->children;
    }

    public function getPeople(): int
    {
        return $this->adults + $this->children;
    }

    public function getResidency(): string
    {
        return $this->residency;
    }

    public function setParty(int $adults, int $children, string $residency): static
    {
        $this->adults = $adults;
        $this->children = $children;
        $this->residency = $residency;

        return $this;
    }

    public function getTier(): int
    {
        return $this->tier;
    }

    public function getTierName(): string
    {
        return $this->tierName;
    }

    public function getSeasonName(): string
    {
        return $this->seasonName;
    }

    public function getSize(): string
    {
        return $this->size;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getEach(): int
    {
        return $this->each;
    }

    public function getGross(): int
    {
        return $this->gross;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setPrice(int $tier, string $tierName, string $seasonName, string $size, string $currency, int $each, int $gross, int $total): static
    {
        $this->tier = $tier;
        $this->tierName = $tierName;
        $this->seasonName = $seasonName;
        $this->size = $size;
        $this->currency = $currency;
        $this->each = $each;
        $this->gross = $gross;
        $this->total = $total;

        return $this;
    }

    public function getPartnerId(): ?string
    {
        return $this->partnerId;
    }

    public function getDiscount(): string
    {
        return $this->discount;
    }

    public function getCreditDays(): int
    {
        return $this->creditDays;
    }

    public function setPartnerTerms(string $partnerId, string $discount, int $creditDays): static
    {
        $this->partnerId = $partnerId;
        $this->discount = $discount;
        $this->creditDays = $creditDays;

        return $this;
    }

    public function getStatus(): TourBookingStatusEnum
    {
        return $this->status;
    }

    public function setStatus(TourBookingStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getHeldUntil(): ?\DateTimeImmutable
    {
        return $this->heldUntil;
    }

    public function setHeldUntil(?\DateTimeImmutable $heldUntil): static
    {
        $this->heldUntil = $heldUntil;

        return $this;
    }

    /** Whether a provisional booking's hold ended before the day given. */
    public function isLapsed(\DateTimeImmutable $today): bool
    {
        return TourBookingStatusEnum::Provisional === $this->status && null !== $this->heldUntil && $this->heldUntil < $today->setTime(0, 0);
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getMadeAt(): \DateTimeImmutable
    {
        return $this->madeAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function setCancelled(\DateTimeImmutable $at, string $reason): static
    {
        $this->status = TourBookingStatusEnum::Cancelled;
        $this->cancelledAt = $at;
        $this->cancellationReason = $reason;

        return $this;
    }
}
