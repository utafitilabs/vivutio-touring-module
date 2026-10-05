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

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Vivutio\Bundle\ConnectBundle\ConnectBundle;
use Vivutio\Bundle\IdentityBundle\IdentityBundle;
use Vivutio\Bundle\PartnerBundle\PartnerBundle;
use Vivutio\Bundle\PlaceBundle\PlaceBundle;
use Vivutio\Bundle\RegistryBundle\RegistryBundle;
use Vivutio\Bundle\ShellBundle\ShellBundle;
use Vivutio\Touring\VivutioTouringBundle;

// As an installation registers them: the core's bundles, then the module.
return [
    FrameworkBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    DoctrineMigrationsBundle::class => ['all' => true],
    SecurityBundle::class => ['all' => true],
    TwigBundle::class => ['all' => true],
    RegistryBundle::class => ['all' => true],
    IdentityBundle::class => ['all' => true],
    ShellBundle::class => ['all' => true],
    PlaceBundle::class => ['all' => true],
    PartnerBundle::class => ['all' => true],
    ConnectBundle::class => ['all' => true],
    VivutioTouringBundle::class => ['all' => true],
];
