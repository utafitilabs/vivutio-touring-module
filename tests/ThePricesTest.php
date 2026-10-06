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
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Entity\TourSeason;

/**
 * How a tour is priced, as drawn (vivutio-designs tours/seasons,
 * tours/configure-prices): the organization's tour seasons, the same every
 * year, one of them for every other day; and each tour's rate card, a price per
 * person by season, tier and group size. A party is priced by the season of
 * its first day.
 */
final class ThePricesTest extends WebTestCase
{
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->migrate();
    }

    public function testTheSeasonsAreTheSameEveryYearAndCoverEveryDay(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->seasons();

        $page = $this->browser->request('GET', '/tours/seasons?year=2027');
        self::assertSame('/tours/seasons', $this->browser->request('GET', '/tours')->filter('.page-head .actions')->selectLink('Seasons')->attr('href'));
        $page = $this->browser->request('GET', '/tours/seasons?year=2027');
        self::assertSame(['Low', 'Mid', 'High'], $page->filter('tr[data-season]')->each(static fn (Crawler $row): string => (string) $row->attr('data-season')));
        self::assertSame(['1 Apr – 19 May', 'Every other day', '1 Jul – 30 Sep, 20 Dec – 10 Jan'], $page->filter('tr[data-season] [data-periods]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame('High', $page->filter('td[data-day="2027-01-05"]')->attr('data-season'));
        self::assertSame('Low', $page->filter('td[data-day="2027-04-01"]')->attr('data-season'));
        self::assertSame('Mid', $page->filter('td[data-day="2027-05-20"]')->attr('data-season'));
    }

    public function testWhatASeasonCannotBeIsRefusedBesideItsField(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->seasons();
        $low = $this->season('Low');

        foreach ([
            ['name', ['name' => 'high']],
            ['periods[0][from]', ['periods[0][from]' => '31 February']],
            ['periods[0][from]', ['periods[0][from]' => '07-15', 'periods[0][to]' => '08-01']],
        ] as [$field, $values]) {
            $page = $this->browser->request('GET', '/tours/seasons/'.$low->getUuid().'/configure');
            $form = $page->selectButton('Save the season')->form();
            $form->disableValidation();
            $page = $this->browser->submit($form->setValues($values));
            self::assertResponseStatusCodeSame(422, $field);
            self::assertSame($field, $page->filter('.field.wrong')->filter('input, select')->attr('name'), $field);
        }
    }

    public function testATourIsPricedByItsRateCardAndAPartyByItsFirstDay(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->seasons();
        $tour = $this->tour();

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/prices');
        self::assertSame(['Details', 'Itinerary', 'Prices'], $page->filter('nav.tabs a')->each(static fn (Crawler $tab): string => trim($tab->text())));
        $this->browser->submit($page->selectButton('Save the prices')->form(['currency' => 'USD', 'brackets' => '2, 3-4, 5-6']));
        self::assertResponseRedirects('/tours/'.$tour->getUuid().'/prices');

        $page = $this->browser->followRedirect();
        self::assertSame(['Silver', 'Gold'], $page->filter('[data-tier]')->each(static fn (Crawler $grid): string => (string) $grid->attr('data-tier')));
        self::assertSame(['Season', '2 people', '3 – 4', '5 – 6'], $page->filter('[data-tier="Silver"] thead th')->each(static fn (Crawler $th): string => trim($th->text())));
        $values = [];
        foreach (['Low' => ['2155.24', '1738.31', '1599.33'], 'Mid' => ['2381.50', '1867.16', '1695.72'], 'High' => ['2847.97', '2173.88', '1949.18']] as $name => $amounts) {
            foreach (array_combine(['2-2', '3-4', '5-6'], $amounts) as $bracket => $amount) {
                $values['rates[0]['.$this->season($name)->getUuid().']['.$bracket.']'] = $amount;
            }
        }
        $this->browser->submit($page->selectButton('Save the prices')->form($values));
        self::assertResponseRedirects('/tours/'.$tour->getUuid().'/prices');

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'?start=2027-07-14&adults=3&children=0&residency=non_resident&tier=0');
        self::assertSame('Silver · High · 3 – 4 people: USD 2,173.88 a person, USD 6,521.64 for 3', trim((string) preg_replace('/\s+/', ' ', $page->filter('[data-price]')->text())));
        self::assertSame('From USD 1,599.33', trim($this->browser->request('GET', '/tours')->filter('tr[data-tour="Northern Circuit"] [data-from]')->text()));

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'?start=2027-07-14&adults=3&children=0&residency=non_resident&tier=1');
        self::assertSame('Gold is not priced for 3 – 4 people in High.', trim($page->filter('[data-price]')->text()));
        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'?start=2027-07-14&adults=7&children=0&residency=non_resident&tier=0');
        self::assertSame('The tour takes 2 to 6 people.', trim($page->filter('[data-price]')->text()));
    }

    public function testWhatAPriceCannotBeIsRefusedBesideItsField(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->seasons();
        $tour = $this->tour();
        $high = $this->season('High');

        foreach ([
            ['currency', ['currency' => 'dollars']],
            ['brackets', ['brackets' => '2, 3-4']],
            ['brackets', ['brackets' => '2-3, 3-6']],
            ['rates[0]['.$high->getUuid().'][2-2]', ['rates[0]['.$high->getUuid().'][2-2]' => 'a lot']],
        ] as [$field, $values]) {
            $rates = str_starts_with($field, 'rates');
            $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/prices');
            $form = $page->selectButton('Save the prices')->form();
            $form->disableValidation();
            $page = $this->browser->submit($form->setValues([...['currency' => 'USD', 'brackets' => '2, 3-4, 5-6'], ...($rates ? [] : $values)]));
            if ($rates) {
                self::assertResponseRedirects();
                $form = $this->browser->followRedirect()->selectButton('Save the prices')->form();
                $form->disableValidation();
                $page = $this->browser->submit($form->setValues($values));
            }
            self::assertResponseStatusCodeSame(422, $field);
            self::assertSame($field, $page->filter('.field.wrong, td.wrong')->filter('input')->attr('name'), $field);
        }
    }

    public function testStaffWhoReadToursSeeThePricesAndChangeNone(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->seasons();
        $tour = $this->tour();

        $sales = (new Department())->setName('Sales')->setAllows(['tours.read']);
        $this->em()->persist($sales);
        $this->signedInAs($this->person('Elia', TierEnum::Staff, ['tours.read', 'tours.manage'], $sales));
        $this->browser->request('GET', '/tours/seasons');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->browser->getCrawler()->filter('form[method="post"]'));
        $this->browser->request('GET', '/tours/'.$tour->getUuid().'/prices');
        self::assertResponseStatusCodeSame(403);
    }

    /** Low 1 Apr – 19 May; Mid every other day; High 1 Jul – 30 Sep and 20 Dec – 10 Jan, as the operator publishes them. */
    private function seasons(): void
    {
        /** @var list<array{string, string, list<array{string, string}>, bool}> $seasons */
        $seasons = [['Low', '1', [['04-01', '05-19']], false], ['Mid', '2', [], true], ['High', '3', [['07-01', '09-30'], ['12-20', '01-10']], false]];
        foreach ($seasons as [$name, $tone, $periods, $rest]) {
            $page = $this->browser->request('GET', '/tours/seasons');
            $this->browser->submit($page->selectButton('Add the season')->form(['name' => $name, 'tone' => $tone]));
            self::assertResponseRedirects('/tours/seasons/'.$this->season($name)->getUuid().'/configure');
            $page = $this->browser->followRedirect();
            $values = ['name' => $name, 'tone' => $tone];
            if ($rest) {
                $values['rest'] = '1';
            }
            foreach ($periods as $i => [$from, $to]) {
                $values['periods['.$i.'][from]'] = $from;
                $values['periods['.$i.'][to]'] = $to;
            }
            $form = $page->selectButton('Save the season')->form();
            $form->disableValidation();
            $this->browser->submit($form->setValues($values));
            self::assertResponseRedirects('/tours/seasons', 302, $name);
        }
    }

    private function tour(): Tour
    {
        $page = $this->browser->request('GET', '/tours');
        $this->browser->submit($page->selectButton('Add the tour')->form(['name' => 'Northern Circuit']));
        $tour = $this->em()->getRepository(Tour::class)->findOneBy(['name' => 'Northern Circuit']);
        self::assertInstanceOf(Tour::class, $tour);
        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/configure');
        $this->browser->submit($page->selectButton('Save tour')->form(['group_min' => '2', 'group_max' => '6']));
        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/itinerary');
        $this->browser->submit($page->selectButton('Add a day')->form(['tiers' => 'Silver, Gold']));

        return $tour;
    }

    private function season(string $name): TourSeason
    {
        $this->em()->clear();
        $season = $this->em()->getRepository(TourSeason::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(TourSeason::class, $season, $name);

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
