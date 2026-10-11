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
use Vivutio\Contracts\Partner\Charge;
use Vivutio\Contracts\Partner\ChargesInterface;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Entity\TourDay;
use Vivutio\Touring\Entity\TourRate;
use Vivutio\Touring\Entity\TourSeason;
use Vivutio\Touring\Enum\SeasonToneEnum;
use Vivutio\Touring\Enum\TourStatusEnum;
use Vivutio\Touring\Tests\Application\StandIn\StandInPlaces;

/**
 * What a tour booking is owed, told through the core as a charge, for whoever
 * records payments or keeps the books: a confirmed booking its total, a
 * cancelled one what its cancellation kept, a held one nothing yet.
 */
final class TheChargesTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        self::mockTime('2026-10-06 09:00:00');
        $this->browser = static::createClient();
        $this->migrate();
    }

    /** A confirmed booking is owed its total: by the guest on the day it was made, by a partner in its days to pay. */
    public function testAConfirmedBookingIsChargedItsTotal(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        [$tour] = $this->tour();
        $direct = $this->book($tour, 'Hansen family', '2027-08-02', '2', '2');
        $agent = (new Partner('Savanna Trails Travel', PartnerKindEnum::TourOperator, 'KE', 'desk@savanna-trails.example'))->setCreditDays(30)->setDiscount('10');
        $this->em()->persist($agent);
        $this->em()->flush();
        $through = $this->book($tour, 'Mollel party', '2027-07-14', '3', '0', $agent->getPartnerId());

        self::assertSame([
            ['tour_booking:'.$direct->getUuid(), 'NC-0001', 'Tour booking', 'Northern Circuit, 2 – 7 Aug 2027', null, 'Hansen family', 'USD', 400000, '2026-10-06', '2026-10-06', 'touring_booking', ['uuid' => (string) $direct->getUuid()]],
            ['tour_booking:'.$through->getUuid(), 'NC-0002', 'Tour booking', 'Northern Circuit, 14 – 19 Jul 2027', $agent->getPartnerId(), 'Mollel party', 'USD', 270000, '2026-10-06', '2026-11-05', 'touring_booking', ['uuid' => (string) $through->getUuid()]],
        ], array_map(static fn (Charge $charge): array => [$charge->key, $charge->reference, $charge->kind, $charge->about, $charge->partnerId, $charge->party, $charge->currency, $charge->amount, $charge->raisedOn->format('Y-m-d'), $charge->dueOn->format('Y-m-d'), $charge->route, $charge->routeParameters], $this->charges()->all()));
    }

    /** A provisional booking is held, not owed; a cancelled one is owed what its cancellation kept, from that day. */
    public function testAHeldBookingIsOwedNothingAndACancelledOneWhatItsCancellationKept(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        [$tour] = $this->tour();
        $this->book($tour, 'Hansen family', '2027-08-02', '2', '0', status: 'provisional');
        self::assertSame([], $this->charges()->all());

        $cancelled = $this->book($tour, 'Mollel party', '2027-07-14', '3', '0');
        self::mockTime('2026-10-09 10:00:00');
        $page = $this->browser->request('GET', '/tours/bookings/'.$cancelled->getUuid());
        $this->browser->submit($page->selectButton('Cancel the booking')->form(['reason' => 'Changed plans']));

        $charge = $this->charges()->find('tour_booking:'.$cancelled->getUuid());
        self::assertNotNull($charge);
        self::assertSame(['Northern Circuit, 14 – 19 Jul 2027, cancelled', 0, '2026-10-09', '2026-10-09'], [$charge->about, $charge->amount, $charge->raisedOn->format('Y-m-d'), $charge->dueOn->format('Y-m-d')]);
    }

    private function charges(): ChargesInterface
    {
        $charges = static::getContainer()->get(ChargesInterface::class);
        self::assertInstanceOf(ChargesInterface::class, $charges);

        return $charges;
    }

    private function book(Tour $tour, string $guest, string $start, string $adults, string $children, string $partner = '', string $status = 'confirmed'): TourBooking
    {
        $page = $this->browser->request('GET', '/tours/bookings/new?tour='.$tour->getUuid().'&start='.$start.'&adults='.$adults.'&children='.$children.'&tier=0');
        $form = $page->selectButton('Record the booking')->form();
        $this->browser->request('POST', (string) $form->getUri(), [...$form->getPhpValues(), 'guest' => $guest, 'status' => $status, 'held_until' => 'provisional' === $status ? '2026-10-20' : '', 'partner' => $partner, 'notes' => '']);
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
