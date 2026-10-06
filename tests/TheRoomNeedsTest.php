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

namespace Vivutio\Touring\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Bundle\PartnerBundle\Entity\Partner;
use Vivutio\Bundle\PartnerBundle\Enum\PartnerKindEnum;
use Vivutio\Contracts\Partner\RoomNeed;
use Vivutio\Contracts\Partner\RoomNeedsInterface;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Entity\TourDay;
use Vivutio\Touring\Entity\TourRate;
use Vivutio\Touring\Entity\TourSeason;
use Vivutio\Touring\Enum\SeasonToneEnum;
use Vivutio\Touring\Enum\TourStatusEnum;
use Vivutio\Touring\Tests\Application\StandIn\StandInPlaces;

/**
 * A tour booking's nights at partners' lodges, told through the core as rooms
 * needed, for whoever requests rooms: each stay in the booking's tier at a
 * partner's lodge, while the booking is still to start and not cancelled.
 */
final class TheRoomNeedsTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        self::mockTime('2026-10-06 09:00:00');
        $this->browser = static::createClient();
        $this->migrate();
    }

    public function testABookingsNightsAtPartnersLodgesAreRoomsNeeded(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        [$tour, $mwangaza, $kimya] = $this->tour();
        $booking = $this->book($tour, 'Hansen family', '2027-08-02', '2', '2');

        self::assertSame([
            ['tour_booking:'.$booking->getUuid().':1', $mwangaza->getPartnerId(), 'NC-0001 · Day 1', 'Hansen family', 4, '2027-08-02', 1, 'Northern Circuit', 'Two children, 9 and 12.'],
            ['tour_booking:'.$booking->getUuid().':3', $kimya->getPartnerId(), 'NC-0001 · Days 3–4', 'Hansen family', 4, '2027-08-04', 2, 'Northern Circuit', 'Two children, 9 and 12.'],
        ], array_map(static fn (RoomNeed $need): array => [$need->key, $need->partnerId, $need->reference, $need->party, $need->people, $need->arrival->format('Y-m-d'), $need->nights, $need->about, $need->notes], $this->needs()->all()));
    }

    public function testACancelledOrStartedBookingNeedsNoRooms(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        [$tour] = $this->tour();
        $cancelled = $this->book($tour, 'Hansen family', '2027-08-02', '2', '0');
        $this->book($tour, 'Mollel party', '2026-10-20', '3', '0');
        $page = $this->browser->request('GET', '/tours/bookings/'.$cancelled->getUuid());
        $this->browser->submit($page->selectButton('Cancel the booking')->form(['reason' => 'Changed plans']));

        self::assertSame(['NC-0002 · Day 1', 'NC-0002 · Days 3–4'], array_map(static fn (RoomNeed $need): string => $need->reference, $this->needs()->all()));
        self::mockTime('2026-10-21 09:00:00');
        self::assertSame([], $this->needs()->all(), 'the Mollel party started yesterday');
    }

    private function needs(): RoomNeedsInterface
    {
        $needs = static::getContainer()->get(RoomNeedsInterface::class);
        self::assertInstanceOf(RoomNeedsInterface::class, $needs);

        return $needs;
    }

    private function book(Tour $tour, string $guest, string $start, string $adults, string $children): TourBooking
    {
        $page = $this->browser->request('GET', '/tours/bookings/new?tour='.$tour->getUuid().'&start='.$start.'&adults='.$adults.'&children='.$children.'&tier=0');
        $this->browser->submit($page->selectButton('Record the booking')->form(['guest' => $guest, 'status' => 'confirmed', 'notes' => 'Hansen family' === $guest ? 'Two children, 9 and 12.' : '']));
        self::assertResponseRedirects();
        $booking = $this->em()->getRepository(TourBooking::class)->findOneBy(['guest' => $guest]);
        self::assertInstanceOf(TourBooking::class, $booking);

        return $booking;
    }

    /**
     * Northern Circuit, open, for 2 to 6 people in Silver, priced all year: day
     * 1 a night at Mwangaza Lodge, day 2 a night at the stand-in lodge (a
     * property, not a partner), days 3–4 two nights at Kimya Tented Camp, a day
     * with its night not set, then the last day.
     *
     * @return array{Tour, Partner, Partner}
     */
    private function tour(): array
    {
        $em = $this->em();
        $mwangaza = new Partner('Mwangaza Lodge', PartnerKindEnum::Accommodation, 'TZ', 'stay@mwangaza.example');
        $kimya = new Partner('Kimya Tented Camp', PartnerKindEnum::Accommodation, 'TZ', 'stay@kimya.example');
        $em->persist($mwangaza);
        $em->persist($kimya);
        $all = (new TourSeason('All year', SeasonToneEnum::Busy))->setForTheRest(true);
        $em->persist($all);
        $tour = (new Tour('Northern Circuit'))->setGroup(2, 6)->setTiers(['Silver'])->setPricing('USD', [[2, 6]])->setStatus(TourStatusEnum::Open);
        $em->persist($tour);
        $em->persist((new TourDay($tour, 1))->setTitle('Arusha')->setStays(['partner:'.$mwangaza->getPartnerId()]));
        $em->persist((new TourDay($tour, 2))->setTitle('The lodge')->setStays([StandInPlaces::KIND.':'.StandInPlaces::LODGE]));
        $em->persist((new TourDay($tour, 3))->setTitle('The central Serengeti')->setNights(2)->setStays(['partner:'.$kimya->getPartnerId()]));
        $em->persist((new TourDay($tour, 4))->setTitle('Not yet set'));
        $em->persist((new TourDay($tour, 5))->setTitle('Departure')->setNights(0));
        $em->persist(new TourRate($tour, $all, 0, 2, 6, '1000.00'));
        $em->flush();

        return [$tour, $mwangaza, $kimya];
    }

    /**
     * @param list<string>|null $grants
     */
    private function person(string $name, TierEnum $tier, ?array $grants = null, ?Department $department = null): User
    {
        $position = null;
        if (null !== $grants) {
            $position = (new Position())->setName($name.'\'s seat')->setGrants($grants);
            $this->em()->persist($position);
        }
        $user = (new User())
            ->setEmail(strtolower($name).'@vivutio-camps.example')
            ->setFirstName($name)
            ->setLastName('Kimaro')
            ->setTier($tier)
            ->setPosition($position)
            ->setDepartment($department)
            ->setPassword('a hash, never a password');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function signedInAs(User $user): void
    {
        $this->browser->restart();
        $this->browser->loginUser($user);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function migrate(): void
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('DROP SCHEMA public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]), $output), $output->fetch());
    }
}
