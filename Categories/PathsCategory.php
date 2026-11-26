<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths\Categories;

use Piwik\Category\Category;

class PathsCategory extends Category
{
    public const ID = 'RebelPaths_Paths';

    protected $id = self::ID;
    protected $order = 12;
    protected $icon = 'rebelPathsIcon';
}
