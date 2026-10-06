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
use Vivutio\Bundle\PlaceBundle\Entity\Destination;
use Vivutio\Bundle\PlaceBundle\Service\DestinationFeeService;
use Vivutio\Touring\Entity\Tour;
use Vivutio\Touring\Tests\Application\StandIn\StandInPlaces;

/**
 * Tours: an itinerary day by day through the core's destinations, where each
 * night is spent (a place a package offers, or an accommodation partner), and
 * what the parks charge a party starting on a day, from the fees the
 * organization entered for each destination.
 */
final class TheToursTest extends WebTestCase
{
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->migrate();
    }

    /** The itinerary is written on one page, as drawn (vivutio-designs tours/configure-itinerary/b): tiers, then the days, one Save. */
    public function testAnItineraryIsWrittenOnOnePage(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $camp = $this->accommodationPartner();
        $tour = $this->addTour('7 Days Safari Tanzania');

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/configure');
        self::assertSame(['Details', 'Itinerary'], $page->filter('nav.tabs a')->each(static fn (Crawler $tab): string => trim($tab->text())));
        $page = $this->itinerary($tour);
        $this->browser->submit($page->selectButton('Add a day')->form(['tiers' => 'Silver, Gold']));
        $page = $this->browser->followRedirect();
        self::assertSame(['days[0][stays][0]', 'days[0][stays][1]'], $page->filter('select[name^="days[0][stays]"]')->each(static fn (Crawler $select): string => (string) $select->attr('name')));

        $this->browser->submit($page->selectButton('Add a day')->form([
            'days[0][title]' => 'Arrival in Arusha',
            'days[0][stays][0]' => 'lodge:'.StandInPlaces::LODGE,
            'days[0][stays][1]' => 'partner:'.$camp->getPartnerId(),
            'days[0][meals][dinner]' => '1',
            'days[0][activities]' => 'Airport pickup and transfer',
        ]));
        $page = $this->browser->followRedirect();
        $this->browser->submit($page->selectButton('Save itinerary')->form([
            'days[1][destinations][0]' => 'tz-serengeti',
            'days[1][nights]' => '2',
            'days[1][stays][0]' => 'lodge:'.StandInPlaces::LODGE,
            'days[1][distance_km]' => '330',
            'days[1][drive_hours]' => '7',
        ]));
        self::assertResponseRedirects('/tours/'.$tour->getUuid().'/itinerary');

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid());
        self::assertSame(['Day 1 · Arrival in Arusha', 'Days 2–3 · Serengeti National Park'], $page->filter('[data-day] h3')->each(static fn (Crawler $title): string => trim($title->text())));
        self::assertSame([['Silver', 'Vivutio Stand-in Lodge'], ['Gold', 'Ngorongoro Rim Camp']], $page->filter('[data-day]')->first()->filter('[data-stay]')->each(static fn (Crawler $stay): array => [trim($stay->filter('span')->text()), trim($stay->filter('b')->text())]));
        self::assertSame('Dinner · Airport pickup and transfer', trim($page->filter('[data-day]')->first()->filter('[data-meta]')->text()));
        self::assertSame('2 nights · 330 km · 7 hours', trim($page->filter('[data-day]')->eq(1)->filter('[data-meta]')->text()));
        self::assertSame('3 days · 3 nights', trim($page->filter('.band [data-length]')->text()));
    }

    public function testDaysAreMovedDuplicatedInsertedAndRemoved(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->addTour('Northern Circuit');
        $this->writeDays($tour, ['Tarangire', 'Serengeti', 'Crater']);

        $this->press($tour, 'Move day 3 up');
        self::assertSame(['Tarangire', 'Crater', 'Serengeti'], $this->titles($tour));
        $this->press($tour, 'Duplicate day 1');
        self::assertSame(['Tarangire', 'Tarangire', 'Crater', 'Serengeti'], $this->titles($tour));
        $this->press($tour, 'Insert a day after day 2');
        self::assertSame(['Tarangire', 'Tarangire', '', 'Crater', 'Serengeti'], $this->titles($tour));
        $this->press($tour, 'Remove day 3');
        $this->press($tour, 'Remove day 1');
        $page = $this->browser->followRedirect();
        self::assertSame(['Tarangire'], $page->filter('details[open] input[name$="[title]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')), 'the day before a removed one, or the first, is open');
        self::assertSame(['Tarangire', 'Crater', 'Serengeti'], $this->titles($tour));
    }

    public function testWhatADayCannotBeIsRefusedBesideItsFieldAndNothingIsSaved(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->addTour('Northern Circuit');
        $this->writeDays($tour, ['Tarangire', 'Serengeti']);

        foreach ([
            ['days[1][destinations][0]', ['days[1][destinations][0]' => 'tz-nowhere']],
            ['days[1][stays][0]', ['days[1][stays][0]' => 'lodge:0199b1c0-0000-7000-8000-00000000dead']],
            ['days[1][nights]', ['days[1][nights]' => 'two']],
            ['days[1][distance_km]', ['days[1][distance_km]' => 'far']],
            ['days[0][drive_hours]', ['days[0][drive_hours]' => '30']],
            ['tiers', ['tiers' => 'Silver, silver']],
        ] as [$field, $values]) {
            $form = $this->itinerary($tour)->selectButton('Save itinerary')->form();
            $form->disableValidation();
            $page = $this->browser->submit($form->setValues([...$values, 'days[0][title]' => 'Changed']));
            self::assertResponseStatusCodeSame(422);
            self::assertSame($field, $page->filter('.field.wrong')->filter('input, select, textarea')->attr('name'), $field);
            self::assertSame(['Tarangire', 'Serengeti'], $this->titles($tour), 'nothing is saved');
        }
    }

    /** What the parks charge a party: a stay of two nights is two days there; entering is charged once. */
    public function testTheParkFeesForAPartyStartingOnADay(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->fee('tz-tarangire', 'entry', 'adult', 'person_day', '60');
        $this->fee('tz-tarangire', 'entry', 'child', 'person_day', '20');
        $this->fee('tz-serengeti', 'entry', 'adult', 'person_day', '80');
        $this->fee('tz-serengeti', 'entry', 'child', 'person_day', '20');
        $this->fee('tz-serengeti', 'concession', 'adult', 'vehicle_entry', '40');
        $this->fee('tz-ngorongoro', 'conservation', 'adult', 'person_entry', '70');
        $this->fee('tz-ngorongoro', 'conservation', 'child', 'person_entry', '20');
        $tour = $this->addTour('Northern Circuit');
        $this->writeDays($tour, ['Tarangire', 'Serengeti', 'The Crater', 'Lake Manyara'], [
            'days[0][destinations][0]' => 'tz-tarangire',
            'days[1][destinations][0]' => 'tz-serengeti',
            'days[1][nights]' => '2',
            'days[2][destinations][0]' => 'tz-ngorongoro',
            'days[3][destinations][0]' => 'tz-lake-manyara',
        ]);

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'?start=2026-11-01&adults=2&children=1&residency=non_resident');
        self::assertSame(['USD 140.00', 'USD 400.00', 'USD 160.00', '—'], $page->filter('[data-day] [data-day-fees]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame('USD 700.00', trim($page->filter('[data-fees-total]')->text()));
        self::assertSame(['No fee entered for Lake Manyara National Park on 5 Nov 2026'], $page->filter('[data-missing]')->each(static fn (Crawler $line): string => trim($line->text())));
    }

    /** A tour opens with at least one day, and is archived and opened again. */
    public function testATourOpensWithADayAndIsArchived(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->addTour('Northern Circuit');

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/configure');
        $page = $this->browser->submit($page->selectButton('Open the tour')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A tour opens with at least one day', $page->filter('.notice.danger')->text());

        $this->writeDays($tour, ['Tarangire']);
        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/configure');
        $this->browser->submit($page->selectButton('Open the tour')->form());
        self::assertSame('Open', trim($this->browser->request('GET', '/tours/'.$tour->getUuid())->filter('.title .chip')->text()));

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/configure');
        $this->browser->submit($page->selectButton('Archive')->form());
        $page = $this->browser->request('GET', '/tours?status=archived');
        self::assertSame(['Northern Circuit'], $page->filter('tr[data-tour]')->each(static fn (Crawler $row): string => (string) $row->attr('data-tour')));
    }

    /** Reading tours and keeping them are two pairs, each a department must allow. */
    public function testStaffDoWhatTheirDepartmentAllows(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->addTour('Northern Circuit');
        $this->writeDays($tour, ['Tarangire']);

        $sales = (new Department())->setName('Sales')->setAllows(['tours.read']);
        $this->em()->persist($sales);
        $this->signedInAs($this->person('Elia', TierEnum::Staff, ['tours.read', 'tours.manage'], $sales));
        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('a[href$="/itinerary"], a[href$="/configure"]'));
        $this->browser->request('GET', '/tours/'.$tour->getUuid().'/itinerary');
        self::assertResponseStatusCodeSame(403);
    }

    /** The module ships the migration its mapping needs, and nothing is left for a diff to write. */
    public function testTheMigrationsBuildWhatTheMappingDescribes(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        self::assertSame(0, $application->run(new ArrayInput(['command' => 'doctrine:schema:validate', '--skip-property-types' => true]), $output), $output->fetch());
    }

    private function itinerary(Tour $tour): Crawler
    {
        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/itinerary');
        self::assertResponseIsSuccessful();

        return $page;
    }

    /**
     * Adds a day for each title, then saves the values given.
     *
     * @param list<string>          $titles
     * @param array<string, string> $values
     */
    private function writeDays(Tour $tour, array $titles, array $values = []): void
    {
        foreach ($titles as $i => $title) {
            $this->browser->submit($this->itinerary($tour)->selectButton('Add a day')->form());
            $values['days['.$i.'][title]'] = $title;
        }
        $form = $this->itinerary($tour)->selectButton('Save itinerary')->form();
        $this->browser->submit($form->setValues($values));
        self::assertResponseRedirects('/tours/'.$tour->getUuid().'/itinerary');
    }

    private function press(Tour $tour, string $button): void
    {
        $page = $this->itinerary($tour);
        $this->browser->submit($page->filter('button[title="'.$button.'"]')->form());
        self::assertResponseStatusCodeSame(302, $button);
    }

    /**
     * @return list<string>
     */
    private function titles(Tour $tour): array
    {
        return $this->itinerary($tour)->filter('input[name$="[title]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value'));
    }

    private function addTour(string $name): Tour
    {
        $page = $this->browser->request('GET', '/tours');
        $this->browser->submit($page->selectButton('Add the tour')->form(['name' => $name]));
        self::assertResponseStatusCodeSame(302);
        $this->em()->clear();
        $tour = $this->em()->getRepository(Tour::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Tour::class, $tour);

        return $tour;
    }

    private function fee(string $key, string $kind, string $guest, string $per, string $amount): void
    {
        $destination = $this->em()->getRepository(Destination::class)->findOneBy(['key' => $key]);
        self::assertInstanceOf(Destination::class, $destination);
        $fees = static::getContainer()->get(DestinationFeeService::class);
        self::assertInstanceOf(DestinationFeeService::class, $fees);
        $fees->add($destination, ['kind' => $kind, 'guest' => $guest, 'residency' => 'non_resident', 'per' => $per, 'amount' => $amount, 'currency' => 'USD', 'valid_from' => '2026-07-01', 'valid_to' => '2027-06-30']);
    }

    private function accommodationPartner(): Partner
    {
        $camp = new Partner('Ngorongoro Rim Camp', PartnerKindEnum::Accommodation, 'TZ', 'stay@rim-camp.example');
        $this->em()->persist($camp);
        $this->em()->persist(new Partner('Savanna Trails Safaris', PartnerKindEnum::TourOperator, 'KE', 'reservations@savanna-trails.example'));
        $this->em()->flush();

        return $camp;
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
