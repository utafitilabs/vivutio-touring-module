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
use Vivutio\Touring\Entity\TourRate;
use Vivutio\Touring\Entity\TourSeason;
use Vivutio\Touring\Enum\SeasonToneEnum;
use Vivutio\Touring\Enum\TourStatusEnum;

/**
 * What cancelling a tour booking costs, as drawn (vivutio-designs
 * tours/cancellation, the Prices tab's Cancellation card, the booking's
 * Cancellation card): the tours' terms, from so many days before a tour
 * starts so much of its price; a tour following them, charging nothing or
 * having its own; a booking keeping the terms in force when it is made, and
 * charged by them when cancelled.
 */
final class TheCancellationTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        self::mockTime('2026-10-06 09:00:00');
        $this->browser = static::createClient();
        $this->migrate();
    }

    public function testTheToursTermsAreKeptAndReadAsAGuestReadsThem(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        self::assertSame('/tours/cancellation', $this->browser->request('GET', '/tours')->filter('.page-head .actions')->selectLink('Cancellation')->attr('href'));

        $this->terms([['60', '25'], ['7', '100'], ['30', '50']]);
        self::assertResponseRedirects('/tours/cancellation');
        $page = $this->browser->followRedirect();
        self::assertSame([
            'Free more than 60 days before it starts',
            '25% from 60 to 31 days before',
            '50% from 30 to 8 days before',
            '100% from 7 days before, and after it starts',
        ], $page->filter('[data-band]')->each(fn (Crawler $band): string => $this->text($band)));
        self::assertSame(['60', '30', '7', '', ''], $page->filter('input[name$="[days]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));
    }

    public function testWhatTermsCannotBeAreRefused(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        foreach ([
            [[['60', '0']], 'from 1 to 100 per cent'],
            [[['sixty', '25']], 'whole number of days'],
            [[['60', '50'], ['30', '25']], 'a later cancellation is never charged less'],
            [[['30', '25'], ['30', '50']], 'given twice'],
        ] as [$rows, $said]) {
            $page = $this->terms($rows, 422);
            self::assertStringContainsString($said, $page->filter('.notice.danger')->text());
        }
    }

    public function testABookingKeepsTheTermsItWasMadeUnderAndIsChargedByThem(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->terms([['60', '25'], ['30', '50'], ['7', '100']]);
        $tour = $this->tour();
        $booking = $this->book($tour, '2026-12-01');

        $this->terms([['90', '100']]);
        $page = $this->browser->request('GET', '/tours/bookings/'.$booking->getUuid());
        self::assertSame('Free more than 60 days before it starts', $this->text($page->filter('[data-terms] [data-band]')->first()));
        self::assertSame('Cancelling today, 56 days before it starts, would cost USD 500.00 (25%).', $this->text($page->filter('[data-charge-today]')));

        $this->browser->submit($page->selectButton('Cancel the booking')->form(['reason' => 'Changed plans']));
        $page = $this->browser->followRedirect();
        self::assertSame('Cancelled on 6 Oct 2026, 56 days before it starts, for USD 500.00: Changed plans', $this->text($page->filter('[data-cancelled]')));
        self::assertCount(0, $page->filter('[data-charge-today]'));
        $kept = $this->em()->getRepository(TourBooking::class)->findOneBy(['reference' => $booking->getReference()]);
        self::assertSame(50000, $kept?->getCancellationCharge());
    }

    public function testATourFollowsTheToursTermsChargesNothingOrHasItsOwn(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->terms([['60', '25'], ['30', '50'], ['7', '100']]);
        $tour = $this->tour();

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/prices');
        self::assertSame('follow', $page->filter('[data-cancellation] select[name="mode"] option[selected]')->attr('value'));
        $this->browser->submit($page->selectButton('Save the cancellation')->form(['mode' => 'own', 'tiers[0][days]' => '14', 'tiers[0][percent]' => '100']));
        self::assertResponseRedirects('/tours/'.$tour->getUuid().'/prices');
        $page = $this->browser->request('GET', '/tours/bookings/'.$this->book($tour, '2026-10-16')->getUuid());
        self::assertSame(['Free more than 14 days before it starts', '100% from 14 days before, and after it starts'], $page->filter('[data-terms] [data-band]')->each(fn (Crawler $band): string => $this->text($band)));
        self::assertSame('Cancelling today, 10 days before it starts, would cost USD 2,000.00 (100%).', $this->text($page->filter('[data-charge-today]')));

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/prices');
        $this->browser->submit($page->selectButton('Save the cancellation')->form(['mode' => 'no_charge']));
        $page = $this->browser->request('GET', '/tours/bookings/'.$this->book($tour, '2026-12-01')->getUuid());
        self::assertSame(['No charge to cancel'], $page->filter('[data-terms] [data-band]')->each(fn (Crawler $band): string => $this->text($band)));
        self::assertSame('Cancelling today is free.', $this->text($page->filter('[data-charge-today]')));

        $page = $this->browser->request('GET', '/tours/'.$tour->getUuid().'/prices');
        $form = $page->selectButton('Save the cancellation')->form(['mode' => 'own']);
        $form->disableValidation();
        $this->browser->submit($form);
        self::assertResponseStatusCodeSame(422);
    }

    public function testStaffWhoReadToursReadTheTermsAndKeepNone(): void
    {
        $sales = (new Department())->setName('Sales')->setAllows(['tours.read', 'tours.manage']);
        $this->em()->persist($sales);
        $this->signedInAs($this->person('Elia', TierEnum::Staff, ['tours.read'], $sales));
        $page = $this->browser->request('GET', '/tours/cancellation');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('form[method="post"]'));
        $this->browser->request('POST', '/tours/cancellation', ['tiers' => [['days' => '60', 'percent' => '25']]]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<array{string, string}> $rows days before it starts, and the share charged
     */
    private function terms(array $rows, int $answered = 302): Crawler
    {
        $page = $this->browser->request('GET', '/tours/cancellation');
        $form = $page->selectButton('Save the terms')->form();
        $form->disableValidation();
        $values = [];
        foreach (array_pad($rows, 5, ['', '']) as $i => [$days, $percent]) {
            $values['tiers['.$i.'][days]'] = $days;
            $values['tiers['.$i.'][percent]'] = $percent;
        }
        $page = $this->browser->submit($form->setValues($values));
        self::assertResponseStatusCodeSame($answered, (string) json_encode($rows));

        return $page;
    }

    private function book(Tour $tour, string $start): TourBooking
    {
        $page = $this->browser->request('GET', '/tours/bookings/new?tour='.$tour->getUuid().'&start='.$start.'&adults=2&children=0&tier=0');
        $this->browser->submit($page->selectButton('Record the booking')->form(['guest' => 'Party of '.$start, 'status' => 'confirmed']));
        self::assertResponseRedirects();
        $booking = $this->em()->getRepository(TourBooking::class)->findOneBy(['guest' => 'Party of '.$start], ['id' => 'DESC']);
        self::assertInstanceOf(TourBooking::class, $booking);

        return $booking;
    }

    /** Northern Circuit, open, for 2 to 6 people, USD 1,000.00 a person every day of the year. */
    private function tour(): Tour
    {
        $em = $this->em();
        $all = (new TourSeason('All year', SeasonToneEnum::Busy))->setForTheRest(true);
        $em->persist($all);
        $tour = (new Tour('Northern Circuit'))->setGroup(2, 6)->setPricing('USD', [[2, 6]])->setStatus(TourStatusEnum::Open);
        $em->persist($tour);
        $em->persist((new TourDay($tour, 1))->setTitle('Arusha'));
        $em->persist(new TourRate($tour, $all, 0, 2, 6, '1000.00'));
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
