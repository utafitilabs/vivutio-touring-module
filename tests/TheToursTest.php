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

    public function testATourIsWrittenDayByDay(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $camp = $this->accommodationPartner();

        self::assertSame('/tours', $this->browser->request('GET', '/')->filter('nav.menu')->selectLink('Tours')->attr('href'));
        $tour = $this->addTour('Northern Circuit');
        self::assertResponseRedirects('/tours/'.$tour->getUuid());

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid());
        self::assertSame('Draft', trim($page->filter('.title .chip')->text()));
        self::assertSame(['Vivutio Stand-in Lodge', 'Ngorongoro Rim Camp'], array_values(array_filter($page->filter('select[name="overnight"] option')->each(static fn (Crawler $option): string => trim($option->text())), static fn (string $name): bool => 'Not set' !== $name)));
        $this->addDay($tour, ['title' => 'Arusha to Tarangire', 'destinations[0]' => 'tz-tarangire', 'overnight' => 'lodge:'.StandInPlaces::LODGE, 'meals[lunch]' => '1', 'meals[dinner]' => '1', 'distance_km' => '120', 'drive_hours' => '2.5']);
        $this->addDay($tour, ['title' => 'Into the Serengeti', 'destinations[0]' => 'tz-serengeti', 'overnight' => 'partner:'.$camp->getPartnerId()]);

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid());
        self::assertSame(['Day 1 · Arusha to Tarangire', 'Day 2 · Into the Serengeti'], $page->filter('[data-day] h3')->each(static fn (Crawler $title): string => trim($title->text())));
        $first = $page->filter('[data-day]')->first();
        self::assertSame('Tarangire National Park', trim($first->filter('[data-destinations]')->text()));
        self::assertSame('Vivutio Stand-in Lodge', trim($first->filter('[data-overnight]')->text()));
        self::assertSame('Lunch, dinner · 120 km · 2.5 hours', trim($first->filter('[data-meta]')->text()));
        self::assertSame('Ngorongoro Rim Camp', trim($page->filter('[data-day]')->eq(1)->filter('[data-overnight]')->text()));

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/configure');
        $this->browser->submit($page->selectButton('Save tour')->form(['name' => 'Northern Circuit', 'summary' => 'Tarangire, the Serengeti and the Crater.', 'group_min' => '2', 'group_max' => '6', 'included' => "Park fees\nFull board", 'excluded' => 'Flights']));
        self::assertResponseRedirects('/tours/'.$tour->getUuid());
        $page = $this->browser->followRedirect();
        self::assertSame(['Park fees', 'Full board'], $page->filter('[data-included] li')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertSame('2 to 6', trim($page->filter('.band [data-group]')->text()));
    }

    public function testADayIsChangedAndRemovedAndTheRestRenumbered(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->addTour('Northern Circuit');
        foreach (['Tarangire', 'Serengeti', 'Crater'] as $title) {
            $this->addDay($tour, ['title' => $title]);
        }

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid());
        $this->browser->click($page->filter('[data-day]')->eq(1)->selectLink('Edit')->link());
        $this->browser->submit($this->browser->getCrawler()->selectButton('Save day')->form(['title' => 'Central Serengeti']));
        self::assertResponseRedirects('/tours/'.$tour->getUuid());

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid());
        $this->browser->submit($page->filter('[data-day]')->first()->selectButton('Remove')->form());
        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid());
        self::assertSame(['Day 1 · Central Serengeti', 'Day 2 · Crater'], $page->filter('[data-day] h3')->each(static fn (Crawler $title): string => trim($title->text())));
    }

    public function testWhatADayCannotBeIsRefusedBesideItsField(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $tour = $this->addTour('Northern Circuit');

        foreach ([
            ['title', ['title' => '']],
            ['destinations[0]', ['title' => 'Somewhere', 'destinations[0]' => 'tz-nowhere']],
            ['overnight', ['title' => 'Somewhere', 'overnight' => 'lodge:0199b1c0-0000-7000-8000-00000000dead']],
            ['distance_km', ['title' => 'Somewhere', 'distance_km' => 'far']],
            ['drive_hours', ['title' => 'Somewhere', 'drive_hours' => '30']],
        ] as [$field, $values]) {
            $page = $this->addDay($tour, $values, 422);
            self::assertSame($field, $page->filter('.field.wrong')->filter('input, select, textarea')->attr('name'), $field);
        }
    }

    /** What the parks charge a party: each day's destinations once, by person a day, a person an entry and a vehicle an entry. */
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
        $this->addDay($tour, ['title' => 'Tarangire', 'destinations[0]' => 'tz-tarangire']);
        $this->addDay($tour, ['title' => 'Into the Serengeti', 'destinations[0]' => 'tz-serengeti']);
        $this->addDay($tour, ['title' => 'Central Serengeti', 'destinations[0]' => 'tz-serengeti']);
        $this->addDay($tour, ['title' => 'The Crater', 'destinations[0]' => 'tz-ngorongoro']);
        $this->addDay($tour, ['title' => 'Lake Manyara', 'destinations[0]' => 'tz-lake-manyara']);

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'?start=2026-11-01&adults=2&children=1&residency=non_resident');
        $fees = $page->filter('[data-fees]');
        self::assertSame(['USD 140.00', 'USD 220.00', 'USD 180.00', 'USD 160.00', '—'], $page->filter('[data-day] [data-day-fees]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame('USD 700.00', trim($fees->filter('[data-fees-total]')->text()));
        self::assertSame(['No fee entered for Lake Manyara National Park on 5 Nov 2026'], $fees->filter('[data-missing]')->each(static fn (Crawler $line): string => trim($line->text())));
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

        $this->addDay($tour, ['title' => 'Tarangire']);
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
        $this->addDay($tour, ['title' => 'Tarangire']);

        $sales = (new Department())->setName('Sales')->setAllows(['tours.read']);
        $this->em()->persist($sales);
        $this->signedInAs($this->person('Elia', TierEnum::Staff, ['tours.read', 'tours.manage'], $sales));
        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('form[method="post"]'));
        $this->browser->request('GET', '/tours/'.$tour->getUuid().'/configure');
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

    /**
     * @param array<string, string|list<string>> $values
     */
    private function addDay(Tour $tour, array $values, int $answered = 302): Crawler
    {
        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid());
        $form = $page->selectButton('Add the day')->form();
        $form->disableValidation();
        $page = $this->browser->submit($form->setValues($values));
        self::assertResponseStatusCodeSame($answered);

        return $page;
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
