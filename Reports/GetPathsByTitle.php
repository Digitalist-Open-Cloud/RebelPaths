<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths\Reports;

use Piwik\Piwik;

class GetPathsByTitle extends GetPaths
{
    protected function init()
    {
        parent::init();

        $this->name = Piwik::translate('RebelPaths_PathsByTitle');
        $this->order = 2;
    }
}
