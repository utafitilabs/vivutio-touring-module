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
use Vivutio\Touring\Entity\TourRate;
use Vivutio\Touring\Entity\TourSeason;
use Vivutio\Touring\Enum\SeasonToneEnum;
use Vivutio\Touring\Enum\TourStatusEnum;

/**
 * A booking's party and dates changed, as drawn (vivutio-designs
 * tours/bookings/change, the booking's Changes card): priced again by the
 * tour's prices today at the discount it was made with, shown before it is
 * saved; on a departure, another departure or more seats, its own seats
 * counted free; the reference, the tier and the terms kept; each change kept
 * on the booking, from and to, with who made it.
 */
final class TheBookingChangesTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        self::mockTime('2026-10-06 09:00:00');
        $this->browser = static::createClient();
        $this->migrate();
    }

    public function testABookingsPartyAndDatesArePricedAgainAndTheChangeKept(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->tour();
        $savanna = $this->partner();
        $booking = $this->book('/tours/bookings/new?tour='.$tour->getUuid().'&start=2027-08-02&adults=2&children=2&tier=0', ['partner' => $savanna->getPartnerId()]);
        $em = $this->em();
        $kept = $em->getRepository(Partner::class)->findOneBy(['name' => 'Savanna Trails Travel']);
        $kept?->setDiscount('20.00');
        $em->flush();

        $page = $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid());
        $page = $this->browser->click($page->filter('[data-actions]')->selectLink('Change the party or dates')->link());
        self::assertSame('/tours/bookings/'.$booking->getUuid().'/change', parse_url((string) $this->browser->getRequest()->getUri(), \PHP_URL_PATH));
        self::assertSame('4 · 2 Aug 2027 · USD 7,608.58', $this->text($page->filter('[data-now] b')));

        $page = $this->browser->submit($page->selectButton('Price it again')->form(['start' => '2027-08-09', 'adults' => '3', 'children' => '2']));
        self::assertResponseIsSuccessful();
        self::assertSame('Silver · High · 5 – 6 people: USD 1,949.18 a person, USD 9,745.90 for 5; Savanna Trails Travel’s 12.5% off makes it USD 8,527.66.', $this->text($page->filter('[data-price]')));
        self::assertSame('NC-0001', $em->getRepository(TourBooking::class)->findOneBy([])?->getReference());
        self::assertSame(760858, $this->booking()->getTotal(), 'pricing again changes nothing');

        $this->browser->submit($page->selectButton('Save the change')->form());
        self::assertResponseRedirects('/tours/bookings/'.$booking->getUuid());
        $page = $this->browser->followRedirect();
        self::assertSame('USD 8,527.66', $this->text($page->filter('[data-total]')));
        self::assertSame('NC-0001', $this->text($page->filter('h1')));
        self::assertSame(['6 Oct 2026 · Baraka Kimaro 4 to 5 people; 2 Aug to 9 Aug 2027; USD 7,608.58 to USD 8,527.66'], $page->filter('[data-change]')->each(fn (Crawler $change): string => $this->text($change->filter('span')).' '.$this->text($change->filter('b'))));
        $changed = $this->booking();
        self::assertSame(['2027-08-09', 3, 2, '12.50', 'Silver'], [$changed->getStart()->format('Y-m-d'), $changed->getAdults(), $changed->getChildren(), $changed->getDiscount(), $changed->getTierName()]);
    }

    public function testWhatAChangeCannotBeIsRefused(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->tour();
        $booking = $this->book('/tours/bookings/new?tour='.$tour->getUuid().'&start=2027-08-02&adults=2&children=0&tier=0');

        foreach ([
            ['start', ['start' => '2026-10-01']],
            ['adults', ['adults' => '7']],
            ['adults', ['adults' => '0', 'children' => '2']],
        ] as [$field, $values]) {
            $page = $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid().'/change');
            $form = $page->selectButton('Save the change')->form();
            $form->disableValidation();
            $page = $this->browser->submit($form->setValues($values));
            self::assertResponseStatusCodeSame(422, $field);
            self::assertSame($field, $page->filter('.field.wrong')->filter('input, select')->attr('name'), $field);
        }
        self::assertSame('2027-08-02', $this->booking()->getStart()->format('Y-m-d'));

        $page = $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid());
        $this->browser->submit($page->selectButton('Cancel the booking')->form(['reason' => 'Changed plans']));
        $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid().'/change');
        self::assertResponseStatusCodeSame(404);
    }

    public function testADepartureBookingMovesToAnotherDepartureItsOwnSeatsCountedFree(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->tour();
        $em = $this->em();
        $first = new TourDeparture($tour, new \DateTimeImmutable('2026-11-07'), 0, 6, 2, 124000);
        $second = new TourDeparture($tour, new \DateTimeImmutable('2026-11-21'), 0, 6, 2, 130000);
        $em->persist($first);
        $em->persist($second);
        $em->flush();
        $booking = $this->book('/tours/bookings/new?departure='.$first->getUuid(), ['adults' => '2']);
        $this->book('/tours/bookings/new?departure='.$first->getUuid(), ['adults' => '3', 'guest' => 'Okafor party']);

        $page = $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid().'/change');
        $form = $page->selectButton('Save the change')->form();
        $form->disableValidation();
        $this->browser->submit($form->setValues(['adults' => '4']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('The departure has 3 seats left.', $this->text($this->browser->getCrawler()->filter('.field.wrong .hint')));

        $page = $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid().'/change');
        $this->browser->submit($page->selectButton('Save the change')->form(['adults' => '3']));
        self::assertResponseRedirects();
        self::assertSame(372000, $this->booking()->getTotal());

        $page = $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid().'/change');
        $this->browser->submit($page->selectButton('Save the change')->form(['departure' => (string) $second->getUuid(), 'adults' => '4']));
        self::assertResponseRedirects();
        $moved = $this->booking();
        self::assertSame(['2026-11-21', 520000, '4 seats'], [$moved->getStart()->format('Y-m-d'), $moved->getTotal(), $moved->getSize()]);
        self::assertSame((string) $second->getUuid(), (string) $moved->getDeparture()?->getUuid());
    }

    public function testOnlyWhoeverManagesBookingsChangesOne(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->tour();
        $booking = $this->book('/tours/bookings/new?tour='.$tour->getUuid().'&start=2027-08-02&adults=2&children=0&tier=0');

        $sales = (new Department())->setName('Sales')->setAllows(['tours.read', 'tour_bookings.read', 'tour_bookings.record', 'tour_bookings.manage']);
        $this->em()->persist($sales);
        $this->signedInAs($this->person('Asha', TierEnum::Staff, ['tours.read', 'tour_bookings.read', 'tour_bookings.record'], $sales));
        self::assertCount(0, $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid())->filter('a:contains("Change the party or dates")'));
        $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid().'/change');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, string> $values
     */
    private function book(string $from, array $values = []): TourBooking
    {
        $page = $this->browser->request('GET', $from);
        $guest = $values['guest'] ?? 'Hansen family';
        $this->browser->submit($page->selectButton('Record the booking')->form([...['guest' => $guest, 'status' => 'confirmed'], ...$values]));
        self::assertResponseRedirects();
        $booking = $this->em()->getRepository(TourBooking::class)->findOneBy(['guest' => $guest]);
        self::assertInstanceOf(TourBooking::class, $booking);

        return $booking;
    }

    private function booking(): TourBooking
    {
        $booking = $this->em()->getRepository(TourBooking::class)->findOneBy(['guest' => 'Hansen family']);
        self::assertInstanceOf(TourBooking::class, $booking);
        $this->em()->refresh($booking);

        return $booking;
    }

    private function partner(): Partner
    {
        $partner = (new Partner('Savanna Trails Travel', PartnerKindEnum::TravelAgent, 'KE', 'bookings@savanna-trails.example'))->setDiscount('12.50')->setCreditDays(30);
        $this->em()->persist($partner);
        $this->em()->flush();

        return $partner;
    }

    /**
     * Northern Circuit, open, for 2 to 6 people in Silver, one day at Arusha;
     * Mid every other day, High 1 Jul – 30 Sep; Silver priced for 2, 3 – 4 and
     * 5 – 6 people in both.
     */
    private function tour(): Tour
    {
        $em = $this->em();
        $mid = (new TourSeason('Mid', SeasonToneEnum::Busy))->setForTheRest(true);
        $high = (new TourSeason('High', SeasonToneEnum::Busiest))->setPeriods([['from' => '07-01', 'to' => '09-30']]);
        $em->persist($mid);
        $em->persist($high);
        $tour = (new Tour('Northern Circuit'))->setGroup(2, 6)->setTiers(['Silver'])->setPricing('USD', [[2, 2], [3, 4], [5, 6]])->setStatus(TourStatusEnum::Open);
        $em->persist($tour);
        $em->persist((new TourDay($tour, 1))->setTitle('Arusha'));
        foreach (['Mid' => [$mid, ['2381.50', '1867.16', '1695.72']], 'High' => [$high, ['2847.97', '2173.88', '1949.18']]] as [$season, $amounts]) {
            foreach ([[2, 2], [3, 4], [5, 6]] as $i => [$min, $max]) {
                $em->persist(new TourRate($tour, $season, 0, $min, $max, $amounts[$i]));
            }
        }
        $em->flush();

        return $tour;
    }

    private function text(Crawler $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->text()));
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
