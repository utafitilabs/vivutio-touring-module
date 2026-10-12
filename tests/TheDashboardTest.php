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
use Symfony\Component\DomCrawler\Crawler;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Bundle\PartnerBundle\Entity\Partner;
use Vivutio\Bundle\PartnerBundle\Enum\PartnerKindEnum;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Entity\TourDay;
use Vivutio\Touring\Entity\TourDeparture;
use Vivutio\Touring\Enum\TourBookingStatusEnum;
use Vivutio\Touring\Enum\TourStatusEnum;

/**
 * What tours put on the organization's dashboard, as drawn (vivutio-designs
 * shell/dashboard): who is travelling, what starts in three weeks and what is
 * held in the band; the bookings starting soon, those held to confirm and the
 * next departures as cards, each for whoever reads its page.
 */
final class TheDashboardTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        self::mockTime('2026-10-12 09:00:00');
        $this->browser = static::createClient();
        $this->migrate();
    }

    public function testTheBandSaysWhoIsTravellingWhatStartsSoonAndWhatIsHeld(): void
    {
        $this->seed();

        $page = $this->home($this->person('Baraka', TierEnum::Admin));

        self::assertSame([
            'Travelling now 2 parties, 9 people',
            'Starting in 3 weeks 6 bookings',
            'Held, to confirm 4 1 lets go tomorrow',
        ], $page->filter('.band .f')->each(static fn (Crawler $f): string => self::words($f)));
    }

    public function testStartingSoonShowsTheNextThreeWeeksSoonestFirst(): void
    {
        $this->seed();

        $card = $this->card($this->home($this->person('Baraka', TierEnum::Admin)), 'Starting soon');

        self::assertSame('Tour bookings · the next three weeks', self::words($card->filter('.panel-head p')));
        self::assertSame([
            'NCS-0004 Achieng party · Kilele Journeys Northern Circuit Safari Sun 18 Oct Confirmed',
            'SPS-0003 Okafor party Southern Parks Safari Thu 22 Oct Confirmed',
            'NCS-0006 Ruto party · Savanna Trails Travel Northern Circuit Safari Sun 25 Oct Held',
            'MIG-0002 Lindqvist family Migration Safari Fri 30 Oct Confirmed',
            'NCS-0007 Mwakyusa party · Neema at the desk Northern Circuit Safari Sun 1 Nov Confirmed',
        ], $card->filter('tbody tr')->each(static fn (Crawler $row): string => self::words($row)));
        self::assertSame(['chip', 'chip', 'chip warn', 'chip', 'chip'], $card->filter('.chip')->each(static fn (Crawler $chip): string => (string) $chip->attr('class')));
        self::assertSame('5 of 6', self::words($card->filter('.panel-foot .state')));
        self::assertSame('All tour bookings', self::words($card->filter('.panel-foot a.more')));
        self::assertSame('/tours/bookings', $card->filter('.panel-foot a.more')->attr('href'));
    }

    public function testHeldToConfirmShowsTheSoonestToLetGoFirst(): void
    {
        $this->seed();

        $card = $this->card($this->home($this->person('Baraka', TierEnum::Admin)), 'Held, to confirm');

        self::assertSame('Tour bookings · the soonest let go first', self::words($card->filter('.panel-head p')));
        self::assertSame([
            'NCS-0006 Ruto party · Savanna Trails Travel held to Tue 13 Oct tomorrow',
            'SPS-0004 Brennan party held to Thu 15 Oct in 3 days',
            'MIG-0003 Haddad party held to Mon 19 Oct in a week',
            'SPS-0005 Wang party held to Wed 21 Oct in 9 days',
        ], $card->filter('tbody tr')->each(static fn (Crawler $row): string => self::words($row)));
        self::assertSame(['chip danger', 'chip warn', 'chip', 'chip'], $card->filter('.chip')->each(static fn (Crawler $chip): string => (string) $chip->attr('class')));
        self::assertSame('4 of 4', self::words($card->filter('.panel-foot .state')));
        self::assertSame('All held bookings', self::words($card->filter('.panel-foot a.more')));
        self::assertSame('/tours/bookings?status=provisional', $card->filter('.panel-foot a.more')->attr('href'));
    }

    public function testNextDeparturesShowThePlacesSoldOnTheDatesAhead(): void
    {
        $this->seed();

        $card = $this->card($this->home($this->person('Baraka', TierEnum::Admin)), 'Next departures');

        self::assertSame('Tours · fixed dates, places sold', self::words($card->filter('.panel-head p')));
        self::assertSame([
            'Short but Sweet Group Trip Wed 4 Nov 5 of 6 places sold',
            'Short but Sweet Group Trip Wed 2 Dec 2 of 6 places sold',
            'Migration Safari Thu 1 Jul 2027 4 of 8 places sold',
        ], $card->filter('tbody tr')->each(static fn (Crawler $row): string => self::words($row)));
        self::assertSame('3 of 3', self::words($card->filter('.panel-foot .state')));
        self::assertSame('All tours', self::words($card->filter('.panel-foot a.more')));
        self::assertSame('/tours', $card->filter('.panel-foot a.more')->attr('href'));
    }

    /** With nothing soon, each card says so, and a hold ending today is the one that lets go. */
    public function testWithNothingSoonTheCardsSaySo(): void
    {
        $tour = $this->tour('Northern Circuit Safari', 5);
        $this->booking($tour, 'NCS-0001', 'Mollel party', '2027-02-01', 2, status: TourBookingStatusEnum::Provisional, heldUntil: '2026-10-12');
        $this->em()->flush();

        $page = $this->home($this->person('Baraka', TierEnum::Admin));

        self::assertSame(['Travelling now 0', 'Starting in 3 weeks 0', 'Held, to confirm 1 1 lets go today'], $page->filter('.band .f')->each(static fn (Crawler $f): string => self::words($f)));
        self::assertSame('Nothing starts in the next three weeks', self::words($this->card($page, 'Starting soon')->filter('[data-card-empty]')));
        self::assertSame('', self::words($this->card($page, 'Starting soon')->filter('.panel-foot .state')));
        self::assertSame('No departures ahead', self::words($this->card($page, 'Next departures')->filter('[data-card-empty]')));
        $held = $this->card($page, 'Held, to confirm');
        self::assertSame('NCS-0001 Mollel party held to Mon 12 Oct today', self::words($held->filter('tbody tr')));
        self::assertSame('chip danger', $held->filter('.chip')->attr('class'));
    }

    public function testWithNothingHeldTheHeldCardSaysSo(): void
    {
        $page = $this->home($this->person('Baraka', TierEnum::Admin));

        self::assertSame('Nothing is held', self::words($this->card($page, 'Held, to confirm')->filter('[data-card-empty]')));
    }

    /** Each figure and card stands for whoever reads its page, and only for them. */
    public function testOnlyWhatThePersonReadsIsShown(): void
    {
        $this->seed();
        $sales = (new Department())->setName('Sales')->setAllows(['tours.read', 'tour_bookings.read']);
        $this->em()->persist($sales);

        $elia = $this->person('Elia', TierEnum::Staff, ['dashboard.read', 'tours.read'], $sales);
        $neema = $this->person('Neema', TierEnum::Staff, ['dashboard.read'], $sales);

        $page = $this->home($elia);
        self::assertCount(0, $page->filter('.band .f'));
        self::assertSame(['Next departures'], $page->filter('[data-cards] .panel-head h2')->each(static fn (Crawler $h): string => self::words($h)));

        $page = $this->home($neema);
        self::assertCount(0, $page->filter('.band .f'));
        self::assertCount(0, $page->filter('[data-cards] .panel'));
        self::assertCount(1, $page->filter('[data-nothing-yet]'));
    }

    /**
     * Monday 12 October 2026 at a tour operator: two parties travelling, six
     * bookings starting in three weeks, four held, three departures ahead,
     * and what none of them counts (ended, cancelled, lapsed, too far off).
     */
    private function seed(): void
    {
        $em = $this->em();
        $kilele = new Partner('Kilele Journeys', PartnerKindEnum::TourOperator, 'TZ', 'desk@kilele.example');
        $savanna = new Partner('Savanna Trails Travel', PartnerKindEnum::TourOperator, 'KE', 'desk@savanna-trails.example');
        $em->persist($kilele);
        $em->persist($savanna);
        $northern = $this->tour('Northern Circuit Safari', 5);
        $southern = $this->tour('Southern Parks Safari', 4);
        $migration = $this->tour('Migration Safari', 3);
        $short = $this->tour('Short but Sweet Group Trip', 2);
        $held = TourBookingStatusEnum::Provisional;

        $this->booking($northern, 'NCS-0001', 'Mollel party', '2026-10-10', 4, 1);
        $this->booking($southern, 'SPS-0001', 'Hansen family', '2026-10-12', 4);
        $this->booking($migration, 'MIG-0001', 'Bauer party', '2026-10-01', 2);
        $this->booking($northern, 'NCS-0002', 'Juma party', '2026-10-11', 2, status: TourBookingStatusEnum::Cancelled);
        $this->booking($northern, 'NCS-0003', 'Pendo party', '2026-10-11', 2, status: $held, heldUntil: '2026-10-09');

        $this->booking($northern, 'NCS-0004', 'Achieng party', '2026-10-18', 2, partner: $kilele);
        $this->booking($southern, 'SPS-0003', 'Okafor party', '2026-10-22', 3);
        $this->booking($northern, 'NCS-0006', 'Ruto party', '2026-10-25', 2, status: $held, heldUntil: '2026-10-13', partner: $savanna);
        $this->booking($migration, 'MIG-0002', 'Lindqvist family', '2026-10-30', 2, 2);
        $this->booking($northern, 'NCS-0007', 'Mwakyusa party', '2026-11-01', 2, bookedBy: 'Neema at the desk');
        $this->booking($southern, 'SPS-0004', 'Brennan party', '2026-11-02', 2, status: $held, heldUntil: '2026-10-15');
        $this->booking($migration, 'MIG-0003', 'Haddad party', '2026-11-03', 2, status: $held, heldUntil: '2026-10-19');
        $this->booking($northern, 'NCS-0008', 'Nyerere party', '2026-10-20', 2, status: $held, heldUntil: '2026-10-11');
        $this->booking($northern, 'NCS-0009', 'Kim party', '2026-10-28', 2, status: TourBookingStatusEnum::Cancelled);
        $this->booking($southern, 'SPS-0005', 'Wang party', '2026-11-15', 2, status: $held, heldUntil: '2026-10-21');

        $november = $this->departure($short, '2026-11-04', 6);
        $december = $this->departure($short, '2026-12-02', 6);
        $july = $this->departure($migration, '2027-07-01', 8);
        $this->departure($short, '2026-10-07', 6);
        $this->departure($short, '2026-11-18', 6)->setCancelled(new \DateTimeImmutable('2026-10-05'), 'Too few travellers');
        $this->booking($short, 'SBS-0001', 'Moshi party', '2026-11-04', 3, departure: $november);
        $this->booking($short, 'SBS-0002', 'Ole party', '2026-11-04', 2, departure: $november);
        $this->booking($short, 'SBS-0003', 'Kariuki party', '2026-11-04', 2, departure: $november, status: TourBookingStatusEnum::Cancelled);
        $this->booking($short, 'SBS-0004', 'Silva party', '2026-12-02', 2, departure: $december);
        $this->booking($migration, 'MIG-0004', 'Owino party', '2027-07-01', 4, departure: $july);
        $em->flush();
    }

    /** A tour of so many days: a night each, then a last day. */
    private function tour(string $name, int $days): Tour
    {
        $em = $this->em();
        $tour = (new Tour($name))->setGroup(1, 12)->setTiers(['Silver'])->setPricing('USD', [[1, 12]])->setStatus(TourStatusEnum::Open);
        $em->persist($tour);
        for ($day = 1; $day <= $days; ++$day) {
            $em->persist((new TourDay($tour, $day))->setTitle('Day '.$day)->setNights($day < $days ? 1 : 0));
        }

        return $tour;
    }

    private function booking(
        Tour $tour,
        string $reference,
        string $guest,
        string $start,
        int $adults,
        int $children = 0,
        TourBookingStatusEnum $status = TourBookingStatusEnum::Confirmed,
        ?string $heldUntil = null,
        ?Partner $partner = null,
        ?string $bookedBy = null,
        ?TourDeparture $departure = null,
    ): TourBooking {
        $booking = (new TourBooking($tour, $reference, $guest, new \DateTimeImmutable($start), new \DateTimeImmutable('2026-09-01 10:00')))
            ->setParty($adults, $children, 'non_resident')
            ->setPrice(0, 'Silver', 'All year', '1 – 12 people', 'USD', 100000, 100000 * ($adults + $children), 100000 * ($adults + $children))
            ->setStatus(TourBookingStatusEnum::Cancelled === $status ? TourBookingStatusEnum::Confirmed : $status)
            ->setHeldUntil(null === $heldUntil ? null : new \DateTimeImmutable($heldUntil))
            ->setBookedBy($bookedBy)
            ->setDeparture($departure);
        if (null !== $partner) {
            $booking->setPartnerTerms($partner->getPartnerId(), '0.00', 30);
        }
        if (TourBookingStatusEnum::Cancelled === $status) {
            $booking->setCancelled(new \DateTimeImmutable('2026-10-05 10:00'), 'Changed plans', 0);
        }
        $this->em()->persist($booking);

        return $booking;
    }

    private function departure(Tour $tour, string $start, int $seats): TourDeparture
    {
        $departure = new TourDeparture($tour, new \DateTimeImmutable($start), 0, $seats, 2, 100000);
        $this->em()->persist($departure);

        return $departure;
    }

    /** The page as a later request draws it, from what was stored, not from what this test holds. */
    private function home(User $user): Crawler
    {
        $this->em()->clear();
        $this->browser->restart();
        $this->browser->loginUser($user);
        $page = $this->browser->request('GET', '/');
        self::assertResponseIsSuccessful();

        return $page;
    }

    private function card(Crawler $page, string $title): Crawler
    {
        $card = $page->filter('[data-cards] .panel')->reduce(static fn (Crawler $panel): bool => self::words($panel->filter('.panel-head h2')) === $title);
        self::assertCount(1, $card, $title);

        return $card;
    }

    /** The words of a node, each element's own apart, as a reader sees them. */
    private static function words(Crawler $node): string
    {
        $words = [];
        foreach ($node as $element) {
            foreach ((new \DOMXPath($element->ownerDocument ?? new \DOMDocument()))->query('.//text()', $element) ?: [] as $text) {
                if ($text instanceof \DOMText) {
                    $words[] = trim((string) preg_replace('/\s+/u', ' ', $text->textContent));
                }
            }
        }

        return implode(' ', array_filter($words, static fn (string $word): bool => '' !== $word));
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
