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

use Vivutio\Bundle\PlaceBundle\Entity\Destination;
use Vivutio\Bundle\PlaceBundle\Entity\DestinationFee;
use Vivutio\Bundle\PlaceBundle\Enum\DestinationKindEnum;
use Vivutio\Bundle\PlaceBundle\Enum\FeePerEnum;
use Vivutio\Bundle\PlaceBundle\Enum\GuestEnum;
use Vivutio\Bundle\PlaceBundle\Enum\ResidencyEnum;
use Vivutio\Bundle\PlaceBundle\Repository\DestinationRepository;
use Vivutio\Bundle\PlaceBundle\Service\DestinationFeeService;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Model\FeeQuote;

/**
 * What the parks charge a party on a tour starting on a day, from the fees in
 * force each day in the core. A stay of three nights is three days at its
 * destinations, and each is charged once a day: a fee a person a day every day, a fee a person an entry and a vehicle
 * an entry on the first day of each visit, a visit being the days in a row a
 * tour is there. The party travels in one vehicle. A city charges no entry,
 * so none is asked of it.
 */
final readonly class ParkFeeService
{
    public const int LARGEST_PARTY = 60;

    public function __construct(
        private DestinationRepository $destinations,
        private DestinationFeeService $fees,
    ) {
    }

    public function quote(Tour $tour, \DateTimeImmutable $start, int $adults, int $children, ResidencyEnum $residency): FeeQuote
    {
        $days = [];
        $totals = [];
        $missing = [];
        $yesterday = [];
        $offset = 0;
        foreach ($tour->getDays() as $stay) {
            $charged = [];
            for ($night = 0; $night < $stay->getLength(); ++$night) {
                $date = $start->modify(\sprintf('+%d days', $offset++));
                foreach ($stay->getDestinations() as $key) {
                    $destination = $this->destinations->findOneBy(['key' => $key]);
                    if (!$destination instanceof Destination) {
                        continue;
                    }
                    $entering = !\in_array($key, $yesterday, true);
                    $forAdults = $this->fees->charged($destination, $date, GuestEnum::Adult, $residency);
                    if ([] === $forAdults) {
                        if (DestinationKindEnum::City !== $destination->getKind()) {
                            $missing[] = \sprintf('No fee entered for %s on %s', $destination->getName(), $date->format('j M Y'));
                        }
                        continue;
                    }
                    foreach ($forAdults as $fee) {
                        self::add($charged, $fee, FeePerEnum::VehicleEntry === $fee->getPer() ? 1 : $adults, $entering);
                    }
                    if ($children > 0) {
                        foreach ($this->fees->charged($destination, $date, GuestEnum::Child, $residency) as $fee) {
                            if (FeePerEnum::VehicleEntry !== $fee->getPer()) {
                                self::add($charged, $fee, $children, $entering);
                            }
                        }
                    }
                }
                $yesterday = $stay->getDestinations();
            }
            $days[$stay->getNumber()] = $charged;
            foreach ($charged as $currency => $cents) {
                $totals[$currency] = ($totals[$currency] ?? 0) + $cents;
            }
        }

        return new FeeQuote($start, $adults, $children, $residency->value, $days, $totals, $missing);
    }

    /**
     * @param array<string, int> $charged
     */
    private static function add(array &$charged, DestinationFee $fee, int $count, bool $entering): void
    {
        if (FeePerEnum::PersonDay !== $fee->getPer() && !$entering) {
            return;
        }
        $charged[$fee->getCurrency()] = ($charged[$fee->getCurrency()] ?? 0) + (int) round((float) $fee->getAmount() * 100) * $count;
    }
}
