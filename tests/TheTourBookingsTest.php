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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DomCrawler\Crawler;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Bundle\PartnerBundle\Entity\Partner;
use Vivutio\Bundle\PartnerBundle\Enum\PartnerKindEnum;
use Vivutio\Bundle\PartnerBundle\Enum\PartnerStatusEnum;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Entity\TourDay;
use Vivutio\Touring\Entity\TourRate;
use Vivutio\Touring\Entity\TourSeason;
use Vivutio\Touring\Enum\SeasonToneEnum;
use Vivutio\Touring\Enum\TourStatusEnum;

/**
 * A tour sold, as drawn (vivutio-designs tours/bookings): booked from a
 * party's price, or from New booking; priced by the tour's prices when it is
 * recorded, less the partner's discount, and kept; held for a while or
 * confirmed, then confirmed or cancelled.
 */
final class TheTourBookingsTest extends WebTestCase
{
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->migrate();
    }

    public function testATourIsBookedFromAPartysPriceAndKeepsThatPrice(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->pricedTour();
        $savanna = $this->partners()['Savanna Trails Travel'];
        $held = (new \DateTimeImmutable('today'))->modify('+14 days');

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'?start=2027-08-02&adults=2&children=2&residency=non_resident&tier=0');
        $book = $page->filter('[data-fees]')->selectLink('Book this party')->attr('href');
        self::assertSame('/tours/bookings/new?tour='.$tour->getUuid().'&start=2027-08-02&adults=2&children=2&residency=non_resident&tier=0', $book);

        $page = $this->browser->request('GET', (string) $book);
        self::assertResponseIsSuccessful();
        self::assertSame('Silver · High · 3 – 4 people: USD 2,173.88 a person, USD 8,695.52 for 4', $this->text($page->filter('[data-price] b')));
        self::assertSame(['Direct', 'Savanna Trails Travel'], $page->filter('select[name="partner"] option')->each(static fn (Crawler $option): string => trim($option->text())));

        $this->browser->submit($page->selectButton('Record the booking')->form([
            'guest' => 'Hansen family',
            'partner' => $savanna->getPartnerId(),
            'booked_by' => 'Ingrid Dahl',
            'their_reference' => 'ST-2207',
            'status' => 'provisional',
            'held_until' => $held->format('Y-m-d'),
            'notes' => 'Two children, 9 and 12.',
        ]));
        $booking = $this->em()->getRepository(TourBooking::class)->findOneBy(['reference' => 'NC-0001']);
        self::assertInstanceOf(TourBooking::class, $booking);
        self::assertResponseRedirects('/tours/bookings/'.$booking->getUuid());

        $page = $this->browser->followRedirect();
        self::assertSame('NC-0001', trim($page->filter('h1')->text()));
        self::assertSame('Held to '.$held->format('j M'), trim($page->filter('[data-status]')->text()));
        self::assertSame('USD 7,608.58', trim($page->filter('[data-total]')->text()));
        self::assertSame([
            'Silver · High, the season of 2 Aug · 3 – 4 people USD 2,173.88 a person',
            'For 4 USD 8,695.52',
            'Savanna Trails Travel’s 12.5% off – USD 1,086.94',
            'Total USD 7,608.58',
        ], $page->filter('[data-price] .fact')->each($this->fact(...)));
        self::assertSame('Savanna Trails Travel · 12.5% off USD 8,695.52 · 30 days to pay', trim($page->filter('[data-partner]')->text()));
        self::assertSame(['Day 1 · Mon 2 Aug · Arusha Mwangaza Lodge'], $page->filter('[data-day]')->each($this->fact(...)));

        $em = $this->em();
        $tour = $em->getRepository(Tour::class)->findOneBy(['name' => 'Northern Circuit']);
        $savanna = $em->getRepository(Partner::class)->findOneBy(['name' => 'Savanna Trails Travel']);
        $high = $em->getRepository(TourRate::class)->findOneBy(['tour' => $tour, 'minPeople' => 3, 'season' => $this->season('High')]);
        self::assertInstanceOf(Tour::class, $tour);
        self::assertInstanceOf(Partner::class, $savanna);
        self::assertInstanceOf(TourRate::class, $high);
        $em->remove($high);
        $em->flush();
        $em->persist(new TourRate($tour, $this->season('High'), 0, 3, 4, '2500.00'));
        $savanna->setDiscount('20.00');
        $em->flush();
        $page = $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid());
        self::assertSame('USD 7,608.58', trim($page->filter('[data-total]')->text()));

        $page = $this->browser->request('GET', '/tours/bookings');
        self::assertSame('/tours/bookings', $page->filter('nav.menu a[title="Tour bookings"]')->attr('href'));
        self::assertSame(['NC-0001'], $page->filter('tr[data-booking]')->each(static fn (Crawler $row): string => (string) $row->attr('data-booking')));
        self::assertSame('USD 7,608.58', trim($page->filter('tr[data-booking] [data-total]')->text()));
        self::assertSame('Northern Circuit', trim($page->filter('tr[data-booking] [data-tour]')->text()));
        self::assertSame('2 Aug 2027 · 1 day', $this->text($page->filter('tr[data-booking] [data-when]')));

        $page = $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid());
        $this->browser->submit($page->selectButton('Confirm the booking')->form());
        self::assertResponseRedirects('/tours/bookings/'.$booking->getUuid());
        $page = $this->browser->followRedirect();
        self::assertSame('Confirmed', trim($page->filter('[data-status]')->text()));

        $this->browser->submit($page->selectButton('Cancel the booking')->form(['reason' => 'The family changed their plans']));
        $page = $this->browser->followRedirect();
        self::assertSame('Cancelled', trim($page->filter('[data-status]')->text()));
        self::assertStringContainsString('The family changed their plans', $page->filter('[data-cancelled]')->text());
        self::assertCount(0, $page->filter('[data-actions]'));
        self::assertSame(['All 1', 'Provisional 0', 'Confirmed 0', 'Cancelled 1'], $this->browser->request('GET', '/tours/bookings')->filter('[data-chip]')->each(fn (Crawler $chip): string => $this->text($chip)));
    }

    public function testThePriceIsShownAgainBeforeAnythingIsRecorded(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->pricedTour();

        $page = $this->browser->request('GET', '/tours/bookings/new');
        self::assertSame('/tours/bookings/new', $this->browser->request('GET', '/tours/bookings')->filter('.page-head .actions')->selectLink('New booking')->attr('href'));
        $page = $this->browser->request('GET', '/tours/bookings/new');
        $this->browser->submit($page->selectButton('Price it again')->form(['tour' => (string) $tour->getUuid(), 'start' => '2027-04-10', 'adults' => '5', 'children' => '0', 'tier' => '0']));
        self::assertResponseIsSuccessful();
        self::assertSame('Silver · Low · 5 – 6 people: USD 1,599.33 a person, USD 7,996.65 for 5', $this->text($this->browser->getCrawler()->filter('[data-price] b')));
        self::assertSame(0, $this->em()->getRepository(TourBooking::class)->count([]));
    }

    public function testWhatABookingCannotBeIsRefusedBesideItsField(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->pricedTour();
        $partners = $this->partners();
        $good = ['tour' => (string) $tour->getUuid(), 'start' => '2027-08-02', 'adults' => '2', 'children' => '0', 'residency' => 'non_resident', 'tier' => '0', 'guest' => 'Mollel party', 'partner' => '', 'status' => 'confirmed', 'held_until' => ''];

        foreach ([
            ['guest', ['guest' => '']],
            ['start', ['start' => '2027-02-30']],
            ['adults', ['adults' => '7']],
            ['adults', ['adults' => '0', 'children' => '2']],
            ['tier', ['tier' => '1']],
            ['partner', ['partner' => $partners['Kilele Lodge']->getPartnerId()]],
            ['partner', ['partner' => $partners['Upepo Tours']->getPartnerId()]],
            ['held_until', ['status' => 'provisional', 'held_until' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d')]],
        ] as [$field, $values]) {
            $page = $this->browser->request('GET', '/tours/bookings/new');
            $form = $page->selectButton('Record the booking')->form();
            $form->disableValidation();
            $page = $this->browser->submit($form->setValues([...$good, ...$values]));
            self::assertResponseStatusCodeSame(422, $field);
            self::assertSame($field, $page->filter('.field.wrong')->filter('input, select')->attr('name'), $field);
        }
        self::assertSame('Gold is not priced for 2 people in High.', trim($this->refusal($tour, ['tier' => '1'])));
        self::assertSame(0, $this->em()->getRepository(TourBooking::class)->count([]));
    }

    public function testStaffSeeTheBookingsTheirSeatAllowsAndChangeOnlyWhatItLets(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->pricedTour();
        $page = $this->browser->request('GET', '/tours/bookings/new?tour='.$tour->getUuid().'&start=2027-08-02&adults=2&children=0&tier=0');
        $this->browser->submit($page->selectButton('Record the booking')->form(['guest' => 'Mollel party', 'status' => 'confirmed']));
        $booking = $this->em()->getRepository(TourBooking::class)->findOneBy([]);
        self::assertInstanceOf(TourBooking::class, $booking);

        $sales = (new Department())->setName('Sales')->setAllows(['tours.read', 'tour_bookings.read', 'tour_bookings.record', 'tour_bookings.manage']);
        $this->em()->persist($sales);
        $this->signedInAs($this->person('Elia', TierEnum::Staff, ['tours.read', 'tour_bookings.read'], $sales));
        $page = $this->browser->request('GET', '/tours/bookings');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('.page-head .actions a'));
        $this->browser->request('GET', '/tours/'.$tour->getUuid().'?start=2027-08-02&adults=2&children=0&tier=0');
        self::assertCount(0, $this->browser->getCrawler()->filter('[data-fees] a'));
        $this->browser->request('GET', '/tours/bookings/new');
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid())->filter('[data-actions]'));

        $this->signedInAs($this->person('Asha', TierEnum::Staff, ['tours.read', 'tour_bookings.read', 'tour_bookings.record'], $this->sales()));
        $this->browser->request('GET', '/tours/bookings/new');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid())->filter('[data-actions]'));

        $this->signedInAs($this->person('Juma', TierEnum::Staff, ['tours.read'], $this->sales()));
        $this->browser->request('GET', '/tours/bookings');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, string> $values
     */
    private function refusal(Tour $tour, array $values): string
    {
        $page = $this->browser->request('GET', '/tours/bookings/new');
        $form = $page->selectButton('Record the booking')->form();
        $form->disableValidation();
        $page = $this->browser->submit($form->setValues([...['tour' => (string) $tour->getUuid(), 'start' => '2027-08-02', 'adults' => '2', 'children' => '0', 'tier' => '0', 'guest' => 'Mollel party', 'status' => 'confirmed'], ...$values]));

        return $page->filter('.field.wrong .hint')->text();
    }

    /**
     * Northern Circuit, open, for 2 to 6 people in Silver and Gold, one day at
     * Arusha, its Silver night at Mwangaza Lodge; Low 1 Apr – 19 May, Mid every other day, High 1 Jul – 30 Sep;
     * Silver priced in each season for 2, 3 – 4 and 5 – 6 people; Gold not.
     */
    private function pricedTour(): Tour
    {
        $em = $this->em();
        $seasons = [
            'Low' => (new TourSeason('Low', SeasonToneEnum::Quiet))->setPeriods([['from' => '04-01', 'to' => '05-19']]),
            'Mid' => (new TourSeason('Mid', SeasonToneEnum::Busy))->setForTheRest(true),
            'High' => (new TourSeason('High', SeasonToneEnum::Busiest))->setPeriods([['from' => '07-01', 'to' => '09-30']]),
        ];
        foreach ($seasons as $season) {
            $em->persist($season);
        }
        $mwangaza = new Partner('Mwangaza Lodge', PartnerKindEnum::Accommodation, 'TZ', 'stay@mwangaza.example');
        $em->persist($mwangaza);
        $tour = (new Tour('Northern Circuit'))->setGroup(2, 6)->setTiers(['Silver', 'Gold'])->setPricing('USD', [[2, 2], [3, 4], [5, 6]])->setStatus(TourStatusEnum::Open);
        $em->persist($tour);
        $em->persist((new TourDay($tour, 1))->setTitle('Arusha')->setStays(['partner:'.$mwangaza->getPartnerId(), null]));
        foreach (['Low' => ['2155.24', '1738.31', '1599.33'], 'Mid' => ['2381.50', '1867.16', '1695.72'], 'High' => ['2847.97', '2173.88', '1949.18']] as $name => $amounts) {
            foreach ([[2, 2], [3, 4], [5, 6]] as $i => [$min, $max]) {
                $em->persist(new TourRate($tour, $seasons[$name], 0, $min, $max, $amounts[$i]));
            }
        }
        $em->flush();

        return $tour;
    }

    /**
     * @return array<string, Partner>
     */
    private function partners(): array
    {
        $partners = [
            'Savanna Trails Travel' => (new Partner('Savanna Trails Travel', PartnerKindEnum::TravelAgent, 'KE', 'bookings@savanna-trails.example'))->setDiscount('12.50')->setCreditDays(30),
            'Kilele Lodge' => new Partner('Kilele Lodge', PartnerKindEnum::Accommodation, 'TZ', 'stay@kilele.example'),
            'Upepo Tours' => (new Partner('Upepo Tours', PartnerKindEnum::TourOperator, 'TZ', 'desk@upepo.example'))->setStatus(PartnerStatusEnum::Archived),
        ];
        foreach ($partners as $partner) {
            $this->em()->persist($partner);
        }
        $this->em()->flush();

        return $partners;
    }

    /** The Sales department, read again: a request in between starts a new entity manager. */
    private function sales(): Department
    {
        $sales = $this->em()->getRepository(Department::class)->findOneBy(['name' => 'Sales']);
        self::assertInstanceOf(Department::class, $sales);

        return $sales;
    }

    /** A fact's label and value, a space between. */
    private function fact(Crawler $fact): string
    {
        return $this->text($fact->filter('span')).' '.$this->text($fact->filter('b'));
    }

    private function text(Crawler $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->text()));
    }

    private function season(string $name): TourSeason
    {
        $season = $this->em()->getRepository(TourSeason::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(TourSeason::class, $season);

        return $season;
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
