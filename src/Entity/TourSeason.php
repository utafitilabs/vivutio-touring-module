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
use Vivutio\Touring\Enum\SeasonToneEnum;
use Vivutio\Touring\Repository\TourSeasonRepository;

/**
 * A tour season of the organization, the same every year: its name, its
 * tone, the spans of days and months it runs ("04-01" to "05-19"; one may
 * cross the new year), and whether it is the season of every day no span
 * names.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: TourSeasonRepository::class)]
#[ORM\Table(name: 'touring_season')]
class TourSeason
{
    public const int NAME_MAX_LENGTH = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: self::NAME_MAX_LENGTH, unique: true)]
    private string $name;

    #[ORM\Column(enumType: SeasonToneEnum::class)]
    private SeasonToneEnum $tone;

    /** @var list<array{from: string, to: string}> each a span of days and months, "MM-DD" */
    #[ORM\Column(type: Types::JSON)]
    private array $periods = [];

    #[ORM\Column]
    private bool $forTheRest = false;

    public function __construct(string $name, SeasonToneEnum $tone)
    {
        $this->uuid = Uuid::v7();
        $this->name = $name;
        $this->tone = $tone;
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

    public function getTone(): SeasonToneEnum
    {
        return $this->tone;
    }

    public function setTone(SeasonToneEnum $tone): static
    {
        $this->tone = $tone;

        return $this;
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    public function getPeriods(): array
    {
        return $this->periods;
    }

    /**
     * @param list<array{from: string, to: string}> $periods
     */
    public function setPeriods(array $periods): static
    {
        $this->periods = $periods;

        return $this;
    }

    public function isForTheRest(): bool
    {
        return $this->forTheRest;
    }

    public function setForTheRest(bool $forTheRest): static
    {
        $this->forTheRest = $forTheRest;

        return $this;
    }

    /** Whether one of its spans names the day, whatever the year. */
    public function runsOn(\DateTimeImmutable $day): bool
    {
        $md = $day->format('m-d');
        foreach ($this->periods as $period) {
            $inside = $period['from'] <= $period['to']
                ? $md >= $period['from'] && $md <= $period['to']
                : $md >= $period['from'] || $md <= $period['to'];
            if ($inside) {
                return true;
            }
        }

        return false;
    }
}
