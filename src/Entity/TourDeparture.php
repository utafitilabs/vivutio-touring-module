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
use Vivutio\Touring\Enum\DepartureStatusEnum;
use Vivutio\Touring\Repository\TourDepartureRepository;

/**
 * A tour leaving on a set day, sold by the seat: in one tier (by its place in
 * the tour's tiers), with so many seats, running once the seats it runs with
 * are sold, at a seat's price in cents of the tour's currency.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: TourDepartureRepository::class)]
#[ORM\Table(name: 'touring_departure')]
#[ORM\UniqueConstraint(columns: ['tour_id', 'start'])]
class TourDeparture
{
    public const int REASON_MAX_LENGTH = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 16, enumType: DepartureStatusEnum::class)]
    private DepartureStatusEnum $status = DepartureStatusEnum::Open;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(length: self::REASON_MAX_LENGTH, nullable: true)]
    private ?string $cancellationReason = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tour $tour,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $start,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $tier,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $seats,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $runsWith,
        #[ORM\Column]
        private int $seat,
    ) {
        $this->uuid = Uuid::v7();
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

    public function getStart(): \DateTimeImmutable
    {
        return $this->start;
    }

    public function getTier(): int
    {
        return $this->tier;
    }

    public function getSeats(): int
    {
        return $this->seats;
    }

    public function getRunsWith(): int
    {
        return $this->runsWith;
    }

    /** A seat's price, in cents. */
    public function getSeat(): int
    {
        return $this->seat;
    }

    public function getStatus(): DepartureStatusEnum
    {
        return $this->status;
    }

    public function setStatus(DepartureStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
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
        $this->status = DepartureStatusEnum::Cancelled;
        $this->cancelledAt = $at;
        $this->cancellationReason = $reason;

        return $this;
    }
}
