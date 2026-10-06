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

namespace Vivutio\Touring\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The activities a tour's day takes that its destinations charge for.
 */
final class Version20261007000900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'touring_tour_day.takes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE touring_tour_day ADD takes JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE touring_tour_day ALTER takes DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE touring_tour_day DROP takes');
    }
}
