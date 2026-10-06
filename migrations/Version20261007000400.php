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
 * A tour's margin wanted and its own costs.
 */
final class Version20261007000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'touring_tour.margin and costs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE touring_tour ADD margin NUMERIC(5, 2) DEFAULT '0.00' NOT NULL");
        $this->addSql("ALTER TABLE touring_tour ADD costs JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE touring_tour ALTER margin DROP DEFAULT');
        $this->addSql('ALTER TABLE touring_tour ALTER costs DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE touring_tour DROP margin, DROP costs');
    }
}
