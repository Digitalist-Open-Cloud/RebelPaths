<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths\Categories;

use Piwik\Category\Subcategory;
use Piwik\Piwik;

class PathsSubcategory extends Subcategory
{
    public const ID = 'RebelPaths_Paths';

    protected $categoryId = 'General_Actions';
    protected $id = self::ID;
    protected $order = 45;

    public function getHelp()
    {
        return '<p>' . Piwik::translate('RebelPaths_PathsHelp') . '</p>';
    }
}
