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

namespace Vivutio\Touring\Shell;

use Vivutio\Contracts\Shell\MenuEntry;
use Vivutio\Contracts\Shell\MenuSourceInterface;
use Vivutio\Touring\Controller\TourController;

/**
 * Tours in the menu, with lucide's route.
 */
final readonly class TouringMenu implements MenuSourceInterface
{
    public function entries(): iterable
    {
        yield new MenuEntry(
            TourController::REGISTER,
            'Tours',
            TourController::READ,
            '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>',
            'tours',
        );
    }
}
