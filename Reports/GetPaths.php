<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths\Reports;

use Piwik\Piwik;
use Piwik\Plugin\Report;
use Piwik\Plugin\ViewDataTable;
use Piwik\Plugins\RebelPaths\Categories\PathsSubcategory;
use Piwik\Plugins\RebelPaths\Columns\PathStep;
use Piwik\Plugins\RebelPaths\Metrics\ExitRate;
use Piwik\Plugins\RebelPaths\RecordBuilders\Paths;
use Piwik\Report\ReportWidgetFactory;
use Piwik\Widget\WidgetsList;

class GetPaths extends Report
{
    protected $recursiveLabelSeparator = ' → ';

    protected function init()
    {
        parent::init();

        $this->name = Piwik::translate('RebelPaths_PathsByUrl');
        $this->documentation = Piwik::translate('RebelPaths_PathsDocumentation', Paths::STEPS);
        $this->categoryId = 'General_Actions';
        $this->subcategoryId = PathsSubcategory::ID;
        $this->dimension = new PathStep();
        $this->metrics = ['nb_visits', 'nb_path_exits'];
        $this->processedMetrics = [new ExitRate()];
        $this->defaultSortColumn = 'nb_visits';
        $this->actionToLoadSubTables = $this->action;
        $this->order = 1;
    }

    // The page shows the paths as the overview widget. The table is there for the API and for exports.
    public function configureWidgets(WidgetsList $widgetsList, ReportWidgetFactory $factory)
    {
    }

    public function configureView(ViewDataTable $view)
    {
        $view->config->columns_to_display = ['label', 'nb_visits', 'nb_path_exits', 'path_exit_rate'];
        $view->config->addTranslation('label', Piwik::translate('RebelPaths_ColumnStep'));
        $view->config->metrics_documentation = $this->getMetricsDocumentation();
        $view->requestConfig->filter_sort_column = 'nb_visits';
        $view->requestConfig->filter_sort_order = 'desc';

        $view->config->show_all_views_icons = false;
        $view->config->show_exclude_low_population = false;
    }
}
