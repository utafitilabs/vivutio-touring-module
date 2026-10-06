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
use Vivutio\Touring\Repository\TourTermsRepository;

/**
 * The organization's terms for every tour that has none of its own, one row:
 * its cancellation tiers, the most days first.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: TourTermsRepository::class)]
#[ORM\Table(name: 'touring_terms')]
class TourTerms
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    /** @var list<array{days: int, percent: int}> from so many days before a tour starts, so much of its price; none, and cancelling is free */
    #[ORM\Column(type: Types::JSON)]
    private array $cancellation = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return list<array{days: int, percent: int}>
     */
    public function getCancellation(): array
    {
        return $this->cancellation;
    }

    /**
     * @param list<array{days: int, percent: int}> $cancellation
     */
    public function setCancellation(array $cancellation): static
    {
        $this->cancellation = $cancellation;

        return $this;
    }
}
