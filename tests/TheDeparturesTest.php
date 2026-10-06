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
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourBooking;
use Vivutio\Touring\Entity\TourDay;
use Vivutio\Touring\Entity\TourDeparture;
use Vivutio\Touring\Entity\TourRate;
use Vivutio\Touring\Entity\TourSeason;
use Vivutio\Touring\Enum\SeasonToneEnum;
use Vivutio\Touring\Enum\TourStatusEnum;

/**
 * A tour leaving on set dates, sold by the seat, as drawn (vivutio-designs
 * tours/departures, A, D and E): its departures kept on a Configure tab, sold
 * from the tour's page, each with a page of its own; a departure runs once the
 * seats it runs with are sold.
 */
final class TheDeparturesTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        self::mockTime('2026-10-06 09:00:00');
        $this->browser = static::createClient();
        $this->migrate();
    }

    public function testADeparturesDatesAreAddedAsARunAndPricedByTheSeat(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->tour();

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/departures');
        self::assertSame(['Details', 'Itinerary', 'Prices', 'Costs', 'Departures'], $page->filter('nav.tabs a')->each(static fn (Crawler $tab): string => trim($tab->text())));
        $this->add(['first' => '2026-11-07', 'repeat' => 'fortnight', 'until' => '2026-12-19']);
        self::assertResponseRedirects('/tours/'.$tour->getUuid().'/departures');
        $this->add(['first' => '2027-07-03', 'seat' => '1999']);

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/departures');
        self::assertSame([
            'Sat 7 Nov 2026 · Silver 0 of 12 sold USD 1,240.00 4 seats to run',
            'Sat 21 Nov 2026 · Silver 0 of 12 sold USD 1,240.00 4 seats to run',
            'Sat 5 Dec 2026 · Silver 0 of 12 sold USD 1,240.00 4 seats to run',
            'Sat 19 Dec 2026 · Silver 0 of 12 sold USD 1,240.00 4 seats to run',
            'Sat 3 Jul 2027 · Silver 0 of 12 sold USD 1,999.00 4 seats to run',
        ], $page->filter('tr[data-departure]')->each(fn (Crawler $row): string => $this->text($row->filter('td')->slice(0, 4))));
    }

    public function testWhatADepartureCannotBeIsRefusedBesideItsField(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->tour();
        $this->add(['first' => '2026-11-07']);

        foreach ([
            ['first', ['first' => '2026-10-01']],
            ['first', ['first' => '2026-11-07']],
            ['until', ['first' => '2026-11-14', 'repeat' => 'week', 'until' => '2026-11-01']],
            ['seats', ['first' => '2026-11-14', 'seats' => '13']],
            ['runs_with', ['first' => '2026-11-14', 'runs_with' => '13']],
            ['seat', ['first' => '2026-11-14', 'seat' => 'a lot']],
            ['seat', ['first' => '2027-07-10']],
        ] as [$field, $values]) {
            $page = $this->add($values, 422);
            self::assertSame($field, $page->filter('.field.wrong')->filter('input, select')->attr('name'), $field);
        }
        self::assertSame('Silver is not priced for 4 – 7 people in High; set a seat’s price.', $this->text($this->add(['first' => '2027-07-10'], 422)->filter('.field.wrong .hint')));
        self::assertSame(1, $this->em()->getRepository(TourDeparture::class)->count([]));
    }

    public function testSeatsAreBookedFromTheToursPageUntilNoneAreLeft(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->tour();
        $this->add(['first' => '2026-11-07']);
        $departure = $this->departure();

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid());
        self::assertSame('Sat 7 Nov 2026 12 left USD 1,240.00 4 seats to run', $this->text($page->filter('[data-departures] tr[data-departure] td')->slice(0, 4)));
        $page = $this->browser->click($page->filter('[data-departures]')->selectLink('Book seats')->link());
        self::assertSame('Silver · the departure of 7 Nov 2026: USD 1,240.00 a seat', $this->text($page->filter('[data-price] b')));
        $page = $this->browser->submit($page->selectButton('Price it again')->form(['adults' => '2']));
        self::assertSame('Silver · the departure of 7 Nov 2026: USD 1,240.00 a seat, USD 2,480.00 for 2', $this->text($page->filter('[data-price] b')));
        $this->browser->submit($page->selectButton('Record the booking')->form(['guest' => 'Mollel party', 'status' => 'confirmed']));
        $booking = $this->em()->getRepository(TourBooking::class)->findOneBy(['guest' => 'Mollel party']);
        self::assertInstanceOf(TourBooking::class, $booking);
        self::assertSame([248000, '2026-11-07', '2 seats'], [$booking->getTotal(), $booking->getStart()->format('Y-m-d'), $booking->getSize()]);

        $this->book(['adults' => '9', 'guest' => 'Okafor party']);
        self::assertResponseRedirects();
        $page = $this->book(['adults' => '2', 'guest' => 'Hansen family'], 422);
        self::assertSame('The departure has 1 seat left.', $this->text($page->filter('.field.wrong .hint')));

        $page = $this->browser->request('GET', '/tours/departures/'.$departure->getUuid());
        self::assertSame('Sat 7 Nov 2026', $this->text($page->filter('h1')));
        self::assertSame('Runs', $this->text($page->filter('[data-runs]')));
        self::assertSame(['11', 'of 12 sold'], [trim((string) $page->filter('[data-sold]')->getNode(0)?->firstChild?->textContent), $this->text($page->filter('[data-sold] em'))]);
        self::assertSame('USD 13,640.00', $this->text($page->filter('[data-takings]')));
        self::assertSame(['Mollel party', 'Okafor party'], $page->filter('[data-booking] [data-guest]')->each(fn (Crawler $guest): string => $this->text($guest)));
    }

    public function testADeparturesSalesCloseAndItIsCancelledOnceItsBookingsAre(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->tour();
        $this->add(['first' => '2026-11-07']);
        $departure = $this->departure();
        $this->book(['adults' => '2', 'guest' => 'Mollel party']);

        $page = $this->browser->request('GET', '/tours/departures/'.$departure->getUuid());
        $this->browser->submit($page->selectButton('Close the sales')->form());
        self::assertResponseRedirects('/tours/departures/'.$departure->getUuid());
        self::assertSame('Sales closed', $this->text($this->browser->followRedirect()->filter('[data-runs]')));
        self::assertCount(0, $this->browser->request('GET', '/tours/'.$tour->getUuid())->filter('[data-departures]')->selectLink('Book seats'));
        $page = $this->book(['adults' => '2', 'guest' => 'Hansen family'], 422);
        self::assertSame('departure', $page->filter('.field.wrong select, .field.wrong input')->attr('name'));

        $page = $this->browser->request('GET', '/tours/departures/'.$departure->getUuid());
        $this->browser->submit($page->selectButton('Cancel the departure')->form(['reason' => 'Too few travellers']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Cancel its 1 booking first', $this->browser->getCrawler()->filter('.notice.danger')->text());

        $booking = $this->em()->getRepository(TourBooking::class)->findOneBy(['guest' => 'Mollel party']);
        self::assertInstanceOf(TourBooking::class, $booking);
        $page = $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid());
        $this->browser->submit($page->selectButton('Cancel the booking')->form(['reason' => 'Changed plans']));
        $page = $this->browser->request('GET', '/tours/departures/'.$departure->getUuid());
        $this->browser->submit($page->selectButton('Cancel the departure')->form(['reason' => 'Too few travellers']));
        self::assertResponseRedirects('/tours/departures/'.$departure->getUuid());
        self::assertSame('Cancelled', $this->text($this->browser->followRedirect()->filter('[data-runs]')));
        self::assertCount(0, $this->browser->request('GET', '/tours/'.$tour->getUuid())->filter('[data-departures] tr[data-departure]'));
    }

    public function testStaffWhoReadToursSeeADepartureAndKeepNone(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->tour();
        $this->add(['first' => '2026-11-07']);
        $departure = $this->departure();

        $sales = (new Department())->setName('Sales')->setAllows(['tours.read', 'tours.manage']);
        $this->em()->persist($sales);
        $this->signedInAs($this->person('Elia', TierEnum::Staff, ['tours.read'], $sales));
        $this->browser->request('GET', '/tours/departures/'.$departure->getUuid());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->browser->getCrawler()->filter('form[method="post"]'));
        $this->browser->request('GET', '/tours/'.$tour->getUuid().'/departures');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, string> $values
     */
    private function add(array $values, int $answered = 302): Crawler
    {
        $tour = $this->em()->getRepository(Tour::class)->findOneBy([]);
        self::assertInstanceOf(Tour::class, $tour);
        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/departures');
        $form = $page->selectButton('Add the departures')->form();
        $form->disableValidation();
        $page = $this->browser->submit($form->setValues([...['repeat' => 'once', 'until' => '', 'tier' => '0', 'seats' => '12', 'runs_with' => '4', 'seat' => ''], ...$values]));
        self::assertResponseStatusCodeSame($answered, (string) json_encode($values));

        return $page;
    }

    /**
     * @param array<string, string> $values
     */
    private function book(array $values, int $answered = 302): Crawler
    {
        $page = $this->browser->request('GET', '/tours/bookings/new?departure='.$this->departure()->getUuid());
        $form = $page->selectButton('Record the booking')->form();
        $form->disableValidation();
        $page = $this->browser->submit($form->setValues([...['children' => '0', 'status' => 'confirmed'], ...$values]));
        self::assertResponseStatusCodeSame($answered, (string) json_encode($values));

        return $page;
    }

    private function departure(): TourDeparture
    {
        $departure = $this->em()->getRepository(TourDeparture::class)->findOneBy([], ['start' => 'ASC']);
        self::assertInstanceOf(TourDeparture::class, $departure);

        return $departure;
    }

    /**
     * Short Northern Loop, open, for 2 to 12 people in Silver, a day at
     * Arusha then a day leaving; Mid every other day, High 1 Jul – 30 Sep;
     * priced for 2 – 3, 4 – 7 and 8 – 12 people in Mid, for 2 – 3 only in High.
     */
    private function tour(): Tour
    {
        $em = $this->em();
        $mid = (new TourSeason('Mid', SeasonToneEnum::Busy))->setForTheRest(true);
        $high = (new TourSeason('High', SeasonToneEnum::Busiest))->setPeriods([['from' => '07-01', 'to' => '09-30']]);
        $em->persist($mid);
        $em->persist($high);
        $tour = (new Tour('Short Northern Loop'))->setGroup(2, 12)->setTiers(['Silver'])->setPricing('USD', [[2, 3], [4, 7], [8, 12]])->setStatus(TourStatusEnum::Open);
        $em->persist($tour);
        $em->persist((new TourDay($tour, 1))->setTitle('Arusha'));
        $em->persist((new TourDay($tour, 2))->setTitle('Departure')->setNights(0));
        $em->persist(new TourRate($tour, $mid, 0, 2, 3, '1500.00'));
        $em->persist(new TourRate($tour, $mid, 0, 4, 7, '1240.00'));
        $em->persist(new TourRate($tour, $mid, 0, 8, 12, '1100.00'));
        $em->persist(new TourRate($tour, $high, 0, 2, 3, '1800.00'));
        $em->flush();

        return $tour;
    }

    private function text(Crawler $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $node->each(static fn (Crawler $part): string => $part->text()))));
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
