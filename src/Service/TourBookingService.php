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
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Vivutio\Bundle\PlaceBundle\Enum\ResidencyEnum;
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Contracts\Partner\PartnerInterface;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Entity\TourDeparture;
use Vivutio\Touring\Enum\DepartureStatusEnum;
use Vivutio\Touring\Enum\TourBookingStatusEnum;
use Vivutio\Touring\Enum\TourStatusEnum;
use Vivutio\Touring\Exception\InvalidTourException;
use Vivutio\Touring\Model\TourPrice;
use Vivutio\Touring\Repository\TourBookingRepository;
use Vivutio\Touring\Repository\TourDepartureRepository;
use Vivutio\Touring\Repository\TourRepository;

/**
 * Tours sold. A booking is for an open tour, a day today or later, a party the
 * tour takes and a tier its prices price on that day; it keeps the price it
 * was made at, less the discount of the partner it came through, a travel
 * agent or operator the organization trades with now. It is held to a day, or
 * confirmed; then confirmed or cancelled.
 */
final readonly class TourBookingService
{
    /** The kinds of partner that sell a tour on. */
    public const array SELLING_KINDS = ['travel_agent', 'tour_operator'];

    public const array FIELDS = ['departure', 'tour', 'start', 'adults', 'children', 'residency', 'tier', 'guest', 'partner', 'booked_by', 'their_reference', 'status', 'held_until', 'notes'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private TourBookingRepository $bookings,
        private TourRepository $tours,
        private TourPriceService $prices,
        private TourSeasonService $seasons,
        private PartnerDirectoryInterface $partners,
        private TourDepartureRepository $departures,
        private TourDepartureService $seats,
    ) {
    }

    /**
     * The bookings, the soonest start first, or those standing one way.
     *
     * @return list<TourBooking>
     */
    public function bookings(?TourBookingStatusEnum $status): array
    {
        return $this->bookings->findBy(null === $status ? [] : ['status' => $status], ['start' => 'ASC', 'reference' => 'ASC']);
    }

    /**
     * @return array<string, int> how many bookings stand each way, by status
     */
    public function counts(): array
    {
        $counts = [];
        foreach (TourBookingStatusEnum::cases() as $status) {
            $counts[$status->value] = $this->bookings->count(['status' => $status]);
        }

        return $counts;
    }

    /**
     * The tours on sale, by name.
     *
     * @return list<Tour>
     */
    public function tours(): array
    {
        return $this->tours->findBy(['status' => TourStatusEnum::Open], ['name' => 'ASC']);
    }

    /**
     * The partners a tour may be sold through now, by name.
     *
     * @return list<PartnerInterface>
     */
    public function partners(): array
    {
        return array_values(array_filter($this->partners->active(), static fn (PartnerInterface $partner): bool => \in_array($partner->getPartnerKind(), self::SELLING_KINDS, true)));
    }

    /**
     * What was typed, every field a string.
     *
     * @param array<mixed> $sent
     *
     * @return array<string, string>
     */
    public static function typed(array $sent): array
    {
        $typed = [];
        foreach (self::FIELDS as $field) {
            $typed[$field] = \is_string($sent[$field] ?? null) ? trim($sent[$field]) : '';
        }
        $typed['children'] = '' === $typed['children'] ? '0' : $typed['children'];
        $typed['residency'] = '' === $typed['residency'] ? ResidencyEnum::NonResident->value : $typed['residency'];
        $typed['tier'] = '' === $typed['tier'] ? '0' : $typed['tier'];

        return $typed;
    }

    /**
     * The price the typed party would be sold at, and what the partner's
     * discount makes it; null until a tour, a day and a party are typed.
     *
     * @param array<string, string> $typed
     *
     * @return array{price: TourPrice, after: ?string}|null
     */
    public function price(array $typed): ?array
    {
        $departure = $this->departureOf($typed['departure']);
        if (null !== $departure) {
            $price = $this->seatPrice($departure, ctype_digit($typed['adults']) && ctype_digit($typed['children']) ? (int) $typed['adults'] + (int) $typed['children'] : 0);
            $partner = '' === $typed['partner'] ? null : $this->sellingPartner($typed['partner']);
            $after = null;
            if ($price->people > 0 && null !== $partner && (float) $partner->getDiscount() > 0) {
                $after = \sprintf('%s’s %s%% off makes it %s.', $partner->getName(), self::share($partner->getDiscount()), TourPriceService::money($price->currency, self::discounted($price->gross(), $partner->getDiscount())));
            }

            return ['price' => $price, 'after' => $after];
        }
        $tour = $this->tourOf($typed['tour']);
        $start = self::day($typed['start']);
        if (null === $tour || null === $start || !ctype_digit($typed['adults']) || !ctype_digit($typed['children']) || !ctype_digit($typed['tier'])) {
            return null;
        }
        $price = $this->prices->quote($tour, $start, (int) $typed['adults'] + (int) $typed['children'], (int) $typed['tier']);
        $partner = '' === $typed['partner'] ? null : $this->sellingPartner($typed['partner']);
        $after = null;
        if ($price->priced && null !== $partner && (float) $partner->getDiscount() > 0) {
            $after = \sprintf('%s’s %s%% off makes it %s.', $partner->getName(), self::share($partner->getDiscount()), TourPriceService::money($price->currency, self::discounted($price->gross(), $partner->getDiscount())));
        }

        return ['price' => $price, 'after' => $after];
    }

    /**
     * @param array<string, string> $typed
     *
     * @throws InvalidTourException
     */
    public function record(array $typed): TourBooking
    {
        $today = $this->clock->now()->setTime(0, 0);
        $departure = null;
        if ('' !== $typed['departure']) {
            $departure = $this->departureOf($typed['departure']);
            if (null === $departure || $departure->getStart() < $today || DepartureStatusEnum::Cancelled === $departure->getStatus()) {
                throw new InvalidTourException('departure', 'Choose a departure still to leave.');
            }
            if (DepartureStatusEnum::Closed === $departure->getStatus()) {
                throw new InvalidTourException('departure', 'Its sales are closed.');
            }
        }
        $tour = $departure?->getTour() ?? $this->tourOf($typed['tour']) ?? throw new InvalidTourException('tour', 'Choose a tour on sale.');
        $start = $departure?->getStart() ?? self::day($typed['start']);
        if (null === $start || $start < $today) {
            throw new InvalidTourException('start', 'The day the tour starts, today or later.');
        }
        if (!ctype_digit($typed['adults']) || !ctype_digit($typed['children']) || (int) $typed['adults'] < 1) {
            throw new InvalidTourException('adults', 'At least one adult; children as a number, 0 for none.');
        }
        $adults = (int) $typed['adults'];
        $children = (int) $typed['children'];
        if (null !== $departure) {
            $left = $departure->getSeats() - $this->seats->sold($departure);
            if ($adults + $children > $left) {
                throw new InvalidTourException('adults', \sprintf('The departure has %d %s left.', $left, 1 === $left ? 'seat' : 'seats'));
            }
        } elseif ($adults + $children < $tour->getGroupMin() || $adults + $children > $tour->getGroupMax()) {
            throw new InvalidTourException('adults', \sprintf('The tour takes %d to %d people.', $tour->getGroupMin(), $tour->getGroupMax()));
        }
        $residency = ResidencyEnum::tryFrom($typed['residency']) ?? throw new InvalidTourException('residency', 'Choose the party’s residency.');
        $tiers = max(1, \count($tour->getTiers()));
        $tier = $departure?->getTier() ?? (ctype_digit($typed['tier']) ? (int) $typed['tier'] : -1);
        if ($tier < 0 || $tier >= $tiers) {
            throw new InvalidTourException('tier', 'Choose a tier the tour is sold in.');
        }
        $price = null === $departure ? $this->prices->quote($tour, $start, $adults + $children, $tier) : $this->seatPrice($departure, $adults + $children);
        if (!$price->priced) {
            throw new InvalidTourException(match (true) {
                '' === $tour->getPriceCurrency() => 'tour', null === $this->seasons->seasonOn($start) => 'start', default => 'tier',
            }, $price->says);
        }

        if ('' === $typed['guest'] || mb_strlen($typed['guest']) > TourBooking::GUEST_MAX_LENGTH) {
            throw new InvalidTourException('guest', 'Who it is for: the lead guest, or the party.');
        }
        $partner = null;
        if ('' !== $typed['partner']) {
            $partner = $this->sellingPartner($typed['partner']) ?? throw new InvalidTourException('partner', 'Choose a travel agent or operator you trade with now, or Direct.');
        }
        foreach (['booked_by' => TourBooking::GUEST_MAX_LENGTH, 'their_reference' => TourBooking::REFERENCE_MAX_LENGTH] as $field => $most) {
            if (mb_strlen($typed[$field]) > $most) {
                throw new InvalidTourException($field, \sprintf('Up to %d characters.', $most));
            }
        }
        $status = TourBookingStatusEnum::tryFrom($typed['status']);
        if (null === $status || TourBookingStatusEnum::Cancelled === $status) {
            throw new InvalidTourException('status', 'Held to a day, or confirmed.');
        }
        $heldUntil = null;
        if (TourBookingStatusEnum::Provisional === $status) {
            $heldUntil = self::day($typed['held_until']);
            if (null === $heldUntil || $heldUntil < $today) {
                throw new InvalidTourException('held_until', 'A provisional booking is held to a day, today or later.');
            }
        }

        $gross = $price->gross();
        $booking = (new TourBooking($tour, $this->reference($tour), $typed['guest'], $start, $this->clock->now()))
            ->setParty($adults, $children, $residency->value)
            ->setBookedBy(self::optional($typed['booked_by']))
            ->setTheirReference(self::optional($typed['their_reference']))
            ->setNotes(self::optional($typed['notes']))
            ->setStatus($status)
            ->setHeldUntil($heldUntil)
            ->setPrice($tier, $price->tier, $price->season, $price->size, $price->currency, $price->each, $gross, null === $partner ? $gross : self::discounted($gross, $partner->getDiscount()))
            ->setDeparture($departure);
        if (null !== $partner) {
            $booking->setPartnerTerms($partner->getPartnerId(), $partner->getDiscount(), $partner->getCreditDays());
        }
        $this->entityManager->persist($booking);
        $this->entityManager->flush();

        return $booking;
    }

    /**
     * @throws InvalidTourException
     */
    public function confirm(TourBooking $booking): void
    {
        if (TourBookingStatusEnum::Provisional !== $booking->getStatus()) {
            throw new InvalidTourException('status', 'Only a provisional booking is confirmed.');
        }
        $booking->setStatus(TourBookingStatusEnum::Confirmed)->setHeldUntil(null);
        $this->entityManager->flush();
    }

    /**
     * @throws InvalidTourException
     */
    public function cancel(TourBooking $booking, string $reason): void
    {
        $reason = trim($reason);
        if (TourBookingStatusEnum::Cancelled === $booking->getStatus()) {
            throw new InvalidTourException('reason', 'It is cancelled already.');
        }
        if ('' === $reason || mb_strlen($reason) > TourBooking::REASON_MAX_LENGTH) {
            throw new InvalidTourException('reason', \sprintf('Why it is cancelled, up to %d characters.', TourBooking::REASON_MAX_LENGTH));
        }
        $booking->setCancelled($this->clock->now(), $reason);
        $this->entityManager->flush();
    }

    /** The partner it came through, archived ones too, for its name. */
    public function partnerOf(TourBooking $booking): ?PartnerInterface
    {
        return null === $booking->getPartnerId() ? null : $this->partners->find($booking->getPartnerId());
    }

    /** "12.5", a discount as it reads. */
    public static function share(string $discount): string
    {
        return rtrim(rtrim(number_format((float) $discount, 2, '.', ''), '0'), '.');
    }

    /** What a gross amount in cents comes to after a discount. */
    public static function discounted(int $gross, string $discount): int
    {
        return $gross - (int) round($gross * (float) $discount / 100);
    }

    /**
     * The departures of a tour still to leave and not cancelled, its sales
     * closed or not.
     *
     * @return list<TourDeparture>
     */
    public function departuresOf(Tour $tour): array
    {
        return $this->seats->upcoming($tour);
    }

    public function departureOf(string $uuid): ?TourDeparture
    {
        return Uuid::isValid($uuid) ? $this->departures->findOneBy(['uuid' => Uuid::fromString($uuid)]) : null;
    }

    /** A party's price on a departure: a seat each, at the departure's price; the seat alone for no party. */
    private function seatPrice(TourDeparture $departure, int $people): TourPrice
    {
        $tour = $departure->getTour();
        $tierName = $tour->getTiers()[$departure->getTier()] ?? 'The tour';
        $currency = $tour->getPriceCurrency();
        $says = \sprintf('%s · the departure of %s: %s a seat', $tierName, $departure->getStart()->format('j M Y'), TourPriceService::money($currency, $departure->getSeat()));
        if ($people > 0) {
            $says .= \sprintf(', %s for %d', TourPriceService::money($currency, $departure->getSeat() * $people), $people);
        }

        return new TourPrice(true, $says, $tierName, $this->seasons->seasonOn($departure->getStart())?->getName() ?? '', \sprintf('%d %s', $people, 1 === $people ? 'seat' : 'seats'), $currency, $departure->getSeat(), $people);
    }

    private function tourOf(string $uuid): ?Tour
    {
        return Uuid::isValid($uuid) ? $this->tours->findOneBy(['uuid' => Uuid::fromString($uuid), 'status' => TourStatusEnum::Open]) : null;
    }

    private function sellingPartner(string $id): ?PartnerInterface
    {
        $partner = $this->partners->find($id);

        return null !== $partner && $partner->isActive() && \in_array($partner->getPartnerKind(), self::SELLING_KINDS, true) ? $partner : null;
    }

    /** The tour's initials, up to four, and the next count no booking has. */
    private function reference(Tour $tour): string
    {
        $initials = '';
        foreach (preg_split('/[^\p{L}]+/u', $tour->getName(), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }
        $initials = mb_substr('' === $initials ? 'TB' : $initials, 0, 4);
        $count = $this->bookings->count(['tour' => $tour]);
        do {
            $reference = \sprintf('%s-%04d', $initials, ++$count);
        } while (null !== $this->bookings->findOneBy(['reference' => $reference]));

        return $reference;
    }

    private static function day(string $typed): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $typed);

        return false === $day || $day->format('Y-m-d') !== $typed ? null : $day;
    }

    private static function optional(string $typed): ?string
    {
        return '' === $typed ? null : $typed;
    }
}
