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
 * Each change of a booking's party or dates, kept on it.
 */
final class Version20261007000700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'touring_booking.changes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE touring_booking ADD changes JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE touring_booking ALTER changes DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE touring_booking DROP changes');
    }
}
