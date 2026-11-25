<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths\Metrics;

use Piwik\DataTable\Row;
use Piwik\Metrics\Formatter;
use Piwik\Piwik;
use Piwik\Plugin\ProcessedMetric;

/**
 * The share of the visits that came to a step and ended there.
 */
class ExitRate extends ProcessedMetric
{
    public function getName()
    {
        return 'path_exit_rate';
    }

    public function getTranslatedName()
    {
        return Piwik::translate('RebelPaths_ColumnExitRate');
    }

    public function getDependentMetrics()
    {
        return ['nb_path_exits', 'nb_visits'];
    }

    public function compute(Row $row)
    {
        return Piwik::getQuotientSafe($this->getMetric($row, 'nb_path_exits'), $this->getMetric($row, 'nb_visits'), 4);
    }

    public function format($value, Formatter $formatter)
    {
        return $formatter->getPrettyPercentFromQuotient($value);
    }
}
