<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths;

use Piwik\API\Request as ApiRequest;
use Piwik\Common;
use Piwik\DataTable;
use Piwik\Piwik;
use Piwik\Plugins\RebelPaths\Categories\PathsCategory;
use Piwik\Plugins\RebelPaths\RecordBuilders\Paths;
use Piwik\Request;
use Piwik\Site;
use Piwik\Tracker\GoalManager;
use Piwik\Widget\Widget;
use Piwik\Widget\WidgetConfig;

/**
 * A page of the paths. Each page shows one view of them, by address or by title.
 */
abstract class PathsPage extends Widget
{
    // The pages by the ID of their subcategory, in the order of the menu, with the key of their help text.
    public const PAGES = [
        'RebelPaths_TopPaths' => 'RebelPaths_TopPathsHelp',
        'RebelPaths_Flow' => 'RebelPaths_FlowHelp',
        'RebelPaths_StepExplorer' => 'RebelPaths_StepExplorerHelp',
        'RebelPaths_AroundAPage' => 'RebelPaths_AroundAPageHelp',
        'RebelPaths_ToAGoal' => 'RebelPaths_ToAGoalHelp',
    ];

    // The ID of the subcategory the page is, set by each page.
    protected const PAGE = '';

    private const TOP_PATHS = 10;

    // ponytail: the explorer gets its steps with the page. Load a step when it is picked if a website
    // has more next steps worth showing than these.
    private const MAX_NEXT_STEPS = 20;
    private const MAX_STEPS_IN_PAGE = 3000;

    private int $budget = 0;

    public static function configure(WidgetConfig $config)
    {
        $config->setCategoryId(PathsCategory::ID);
        $config->setSubcategoryId(static::PAGE);
        $config->setName(static::PAGE);
        $config->setIsWide();
        $config->setOrder(1);
    }

    public function render()
    {
        $request = Request::fromRequest();
        $idSite = $request->getIntegerParameter('idSite', 0);
        Piwik::checkUserHasViewAccess($idSite);

        $views = [];
        foreach (['urls' => 'getPaths', 'titles' => 'getPathsByTitle'] as $by => $method) {
            $table = ApiRequest::processRequest('RebelPaths.' . $method, [
                'idSite' => $idSite,
                'period' => $request->getStringParameter('period', 'day'),
                'date' => $request->getStringParameter('date', 'yesterday'),
                'segment' => $request->getStringParameter('segment', ''),
                'expanded' => 1,
                'filter_limit' => -1,
                'disable_generic_filters' => 1,
            ], []);
            $table = $table instanceof DataTable ? $table : new DataTable();

            $top = [];
            $this->collectPaths($table, [], $top);
            usort($top, fn (array $a, array $b) => $b['visits'] <=> $a['visits']);
            $top = array_slice($top, 0, self::TOP_PATHS);

            $this->budget = self::MAX_STEPS_IN_PAGE;
            $views[$by] = ['top' => $top, 'max' => $top[0]['visits'] ?? 0, 'steps' => $this->toSteps($table)];
        }

        $goals = [];
        foreach (ApiRequest::processRequest('Goals.getGoals', ['idSite' => $idSite, 'filter_limit' => -1], []) as $goal) {
            $goals[(int) $goal['idgoal']] = Common::unsanitizeInputValue($goal['name']);
        }
        if (Site::isEcommerceEnabledFor($idSite)) {
            $goals[GoalManager::IDGOAL_ORDER] = Piwik::translate('RebelPaths_EcommerceOrders');
        }

        return $this->renderTemplate('pathsPage', [
            'page' => static::PAGE,
            'goals' => $goals,
            'views' => $views,
            'steps' => array_map(fn (array $view) => $view['steps'], $views),
            'maxSteps' => Paths::STEPS,
            'texts' => [
                'step' => Piwik::translate('RebelPaths_StepNumber'),
                'leftHere' => Piwik::translate('RebelPaths_ShareLeftHere'),
                'leftAfter' => Piwik::translate('RebelPaths_LeftAfter'),
                'others' => Piwik::translate('RebelPaths_OtherPages'),
                'noData' => Piwik::translate('CoreHome_ThereIsNoDataForThisReport'),
                'pick' => Piwik::translate('RebelPaths_PickAPage'),
                'search' => Piwik::translate(Paths::SEARCH),
                'flow' => Piwik::translate('RebelPaths_Flow'),
                'loading' => Piwik::translate('General_LoadingData'),
                'thePage' => Piwik::translate('RebelPaths_ThePage'),
                'visits' => Piwik::translate('General_ColumnNbVisits'),
                'startedHere' => Piwik::translate('RebelPaths_StartedHere'),
                'endedHere' => Piwik::translate('RebelPaths_EndedHere'),
                'convertedOn' => Piwik::translate('RebelPaths_ConvertedOn'),
                'converted' => Piwik::translate('RebelPaths_VisitsConverted'),
                'onTheWay' => Piwik::translate('RebelPaths_OnTheWayRow'),
                'threeBefore' => Piwik::translate('RebelPaths_ThreeStepsBefore'),
                'around' => [
                    '-2' => Piwik::translate('RebelPaths_TwoStepsBefore'),
                    '-1' => Piwik::translate('RebelPaths_OneStepBefore'),
                    '1' => Piwik::translate('RebelPaths_OneStepAfter'),
                    '2' => Piwik::translate('RebelPaths_TwoStepsAfter'),
                ],
                'flowNode' => Piwik::translate('RebelPaths_FlowNode'),
                'flowNodeOne' => Piwik::translate('RebelPaths_FlowNodeOne'),
            ],
        ]);
    }

    /**
     * Every path that visits ended on, with how many did.
     *
     * @param string[] $trail
     * @param array<int, array{steps: string[], visits: int}> $paths
     */
    private function collectPaths(DataTable $table, array $trail, array &$paths): void
    {
        foreach ($table->getRowsWithoutSummaryRow() as $row) {
            $steps = array_merge($trail, [Common::unsanitizeInputValue((string) $row->getColumn('label'))]);
            if ((int) $row->getColumn('nb_path_exits') > 0) {
                $paths[] = ['steps' => $steps, 'visits' => (int) $row->getColumn('nb_path_exits')];
            }
            if ($row->getSubtable()) {
                $this->collectPaths($row->getSubtable(), $steps, $paths);
            }
        }
    }

    /**
     * The rows of a step for the explorer, the most visited first: label, visits, visits that left
     * and the next steps. What does not fit is one last row without a label.
     *
     * @return array<int, array{l: string|null, v: int, x: int, c: array}>
     */
    private function toSteps(DataTable $table): array
    {
        $rows = $table->getRowsWithoutSummaryRow();
        usort($rows, fn ($a, $b) => $b->getColumn('nb_visits') <=> $a->getColumn('nb_visits'));

        $steps = [];
        $others = $table->getSummaryRow() ? (int) $table->getSummaryRow()->getColumn('nb_visits') : 0;
        foreach ($rows as $index => $row) {
            if ($index >= self::MAX_NEXT_STEPS || $this->budget <= 0) {
                $others += (int) $row->getColumn('nb_visits');
                continue;
            }
            $this->budget--;
            $steps[] = [
                'l' => Common::unsanitizeInputValue((string) $row->getColumn('label')),
                'v' => (int) $row->getColumn('nb_visits'),
                'x' => (int) $row->getColumn('nb_path_exits'),
                'c' => $row->getSubtable() ? $this->toSteps($row->getSubtable()) : [],
            ];
        }
        if ($others > 0) {
            $steps[] = ['l' => null, 'v' => $others, 'x' => 0, 'c' => []];
        }

        return $steps;
    }
}
