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
use Vivutio\Bundle\PlaceBundle\Entity\Destination;
use Vivutio\Bundle\PlaceBundle\Entity\DestinationFee;
use Vivutio\Bundle\PlaceBundle\Enum\FeeKindEnum;
use Vivutio\Bundle\PlaceBundle\Enum\FeePerEnum;
use Vivutio\Bundle\PlaceBundle\Enum\GuestEnum;
use Vivutio\Bundle\PlaceBundle\Enum\ResidencyEnum;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourDay;
use Vivutio\Touring\Entity\TourRate;
use Vivutio\Touring\Entity\TourSeason;
use Vivutio\Touring\Enum\SeasonToneEnum;
use Vivutio\Touring\Enum\TourStatusEnum;
use Vivutio\Touring\Tests\Application\StandIn\StandInPlaces;

/**
 * A tour's costs, as drawn (vivutio-designs tours/cost-sheet, A): each price
 * of the rate card beside what a person costs, its margin and the price the
 * margin wanted calls for; a cell's costs broken down; a tier's prices taken
 * over only when asked. Each season is costed from its first day to come; a
 * size from the smallest party it takes.
 */
final class TheCostsTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        self::mockTime('2026-10-06 09:00:00');
        $this->browser = static::createClient();
        $this->migrate();
    }

    public function testEachPriceIsShownBesideWhatAPersonCosts(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->tour();

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/costs');
        self::assertSame(['Details', 'Itinerary', 'Prices', 'Costs'], $page->filter('nav.tabs a')->each(static fn (Crawler $tab): string => trim($tab->text())));
        $this->browser->submit($page->selectButton('Save the costs')->form([
            'margin' => '20',
            'costs[0][name]' => 'Vehicle', 'costs[0][per]' => 'group', 'costs[0][amount]' => '900',
            'costs[1][name]' => 'Water', 'costs[1][per]' => 'person', 'costs[1][amount]' => '10',
        ]));
        self::assertResponseRedirects('/tours/'.$tour->getUuid().'/costs');

        $page = $this->browser->followRedirect();
        $high = '[data-cell="Silver · High · 3 – 4"]';
        self::assertSame('USD 700.00', $this->text($page->filter($high.' [data-price]')));
        self::assertSame('cost 499.00 · at 20%: 623.75', $this->text($page->filter($high.' [data-cost]')));
        self::assertSame('28.7%', $this->text($page->filter($high.' [data-margin]')));
        self::assertSame('7.2%', $this->text($page->filter('[data-cell="Silver · Mid · 3 – 4"] [data-margin]')));
        self::assertSame('Not sold', $this->text($page->filter('[data-cell="Silver · Mid · 2"] [data-price]')));
        self::assertSame('No rate for Vivutio Stand-in Lodge on 1 Apr 2027', $this->text($page->filter('[data-cell="Silver · Low · 2"] [data-cost]')));

        $page = $this->browser->click($page->filter($high.' a')->link());
        self::assertSame('Silver · High · 3 – 4 people', $this->text($page->filter('[data-breakdown] h2')));
        self::assertSame('For 3, starting 1 Jul 2027', $this->text($page->filter('[data-breakdown] .panel-head p')));
        self::assertSame([
            'Day 1 · Vivutio Stand-in Lodge · 1 night 130.00',
            'Day 1 · park fees 59.00',
            'Vehicle, shared by 3 300.00',
            'Water 10.00',
            'Cost a person USD 499.00',
            'Sold at USD 700.00',
            'Margin 28.7%',
        ], $page->filter('[data-breakdown] .fact')->each(fn (Crawler $fact): string => $this->text($fact->filter('span')).' '.$this->text($fact->filter('b'))));
    }

    public function testATiersPricesAreTakenOverFromItsCostsOnlyWhenAsked(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->tour();
        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/costs');
        $this->browser->submit($page->selectButton('Save the costs')->form(['margin' => '20', 'costs[0][name]' => 'Vehicle', 'costs[0][per]' => 'group', 'costs[0][amount]' => '900', 'costs[1][name]' => 'Water', 'costs[1][per]' => 'person', 'costs[1][amount]' => '10']));
        self::assertSame(['3-4' => '700.00'], $this->prices('High'), 'saving the costs leaves the prices');

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/costs');
        $this->browser->submit($page->selectButton('Use the prices at 20% for Silver')->form());
        self::assertResponseRedirects('/tours/'.$tour->getUuid().'/costs');
        self::assertSame(['2-2' => '811.25', '3-4' => '623.75', '5-6' => '473.75'], $this->prices('High'));
        self::assertSame(['2-2' => '767.50', '3-4' => '580.00', '5-6' => '430.00'], $this->prices('Mid'));
        self::assertSame([], $this->prices('Low'), 'a cell with no cost keeps its price, here none');
    }

    public function testWhatACostCannotBeIsRefusedBesideItsField(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->tour();
        foreach ([
            ['margin', ['margin' => 'lots']],
            ['margin', ['margin' => '100']],
            ['costs[0][name]', ['costs[0][name]' => '', 'costs[0][amount]' => '900']],
            ['costs[0][per]', ['costs[0][name]' => 'Vehicle', 'costs[0][per]' => 'weekly', 'costs[0][amount]' => '900']],
            ['costs[0][amount]', ['costs[0][name]' => 'Vehicle', 'costs[0][amount]' => 'nine hundred']],
        ] as [$field, $values]) {
            $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/costs');
            $form = $page->selectButton('Save the costs')->form();
            $form->disableValidation();
            $page = $this->browser->submit($form->setValues($values));
            self::assertResponseStatusCodeSame(422, $field);
            self::assertSame($field, $page->filter('.wrong input, .wrong select')->attr('name'), $field);
        }
    }

    public function testStaffWhoReadToursSeeNoCosts(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->tour();
        $sales = (new Department())->setName('Sales')->setAllows(['tours.read']);
        $this->em()->persist($sales);
        $this->signedInAs($this->person('Elia', TierEnum::Staff, ['tours.read'], $sales));
        $this->browser->request('GET', '/tours/'.$tour->getUuid().'/costs');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<string, string> a season's Silver prices, by bracket
     */
    private function prices(string $season): array
    {
        $prices = [];
        foreach ($this->em()->getRepository(TourRate::class)->findBy(['season' => $this->season($season)], ['minPeople' => 'ASC']) as $rate) {
            $prices[$rate->getMinPeople().'-'.$rate->getMaxPeople()] = $rate->getAmount();
        }

        return $prices;
    }

    /**
     * Northern Circuit, open, for 2 to 6 people in Silver: a day at Lake
     * Manyara and its night at the stand-in lodge, then a day leaving; Low
     * 1 Apr – 19 May, Mid every other day, High 1 Jul – 30 Sep; sold at USD 700
     * in High and USD 500 in Mid for 3 – 4 people, at nothing else.
     */
    private function tour(): Tour
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
        $manyara = $em->getRepository(Destination::class)->findOneBy(['key' => 'tz-lake-manyara-national-park']);
        self::assertInstanceOf(Destination::class, $manyara);
        $em->persist(new DestinationFee($manyara, FeeKindEnum::Entry, GuestEnum::Adult, ResidencyEnum::NonResident, FeePerEnum::PersonDay, '59.00', 'USD', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2030-12-31')));
        $tour = (new Tour('Northern Circuit'))->setGroup(2, 6)->setTiers(['Silver'])->setPricing('USD', [[2, 2], [3, 4], [5, 6]])->setStatus(TourStatusEnum::Open);
        $em->persist($tour);
        $em->persist((new TourDay($tour, 1))->setTitle('Lake Manyara')->setDestinations(['tz-lake-manyara-national-park'])->setStays([StandInPlaces::KIND.':'.StandInPlaces::LODGE]));
        $em->persist((new TourDay($tour, 2))->setTitle('Departure')->setNights(0));
        $em->persist(new TourRate($tour, $seasons['High'], 0, 3, 4, '700.00'));
        $em->persist(new TourRate($tour, $seasons['Mid'], 0, 3, 4, '500.00'));
        $em->flush();

        return $tour;
    }

    private function season(string $name): TourSeason
    {
        $season = $this->em()->getRepository(TourSeason::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(TourSeason::class, $season);

        return $season;
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
