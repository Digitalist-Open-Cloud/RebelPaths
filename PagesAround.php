<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths;

use Piwik\ArchiveProcessor\Parameters;
use Piwik\Common;
use Piwik\DataAccess\LogAggregator;
use Piwik\Db;
use Piwik\Period;
use Piwik\Period\Factory as PeriodFactory;
use Piwik\Plugins\RebelPaths\RecordBuilders\Paths;
use Piwik\Segment;
use Piwik\Site;
use Piwik\Tracker\Action;

/**
 * What visits viewed in the steps around a point of the visit: the first time they came to a
 * page, or the first time they converted a goal. It is read from the logs when it is asked for.
 *
 * ponytail: the page views of the period are read for each question. Archive the most asked
 * ones if this is too slow on a large website.
 */
class PagesAround
{
    public const STEPS = 2;
    public const STEPS_TO_GOAL = 3;

    private const MAX_PAGES_OF_A_STEP = 8;
    private const MAX_PAGES_ON_THE_WAY = 10;

    private const ACTIONS = 'log_link_visit_action';

    private Site $site;
    private Period $range;
    private LogAggregator $logAggregator;
    private bool $byUrl;
    private string $host;

    // Narrows a query to the visits of the segment, as SQL to add to a condition, and what it binds.
    private string $visitsSql = '';
    private array $visitsBind = [];

    public function __construct(int $idSite, string $period, string $date, bool $byUrl, string $segment)
    {
        $this->site = new Site($idSite);
        $this->range = PeriodFactory::build($period, $date);
        $this->logAggregator = new LogAggregator(new Parameters($this->site, $this->range, new Segment('', [$idSite])));
        $this->byUrl = $byUrl;
        $this->host = Paths::getHost($this->site->getMainUrl());

        // A segment on pages would leave only the page views it matches, so it picks the visits instead.
        if ($segment !== '') {
            $segmented = new LogAggregator(new Parameters($this->site, $this->range, new Segment($segment, [$idSite], $this->range->getDateTimeStart(), $this->range->getDateTimeEnd())));
            $visits = $segmented->generateQuery('log_visit.idvisit', ['log_visit'], $segmented->getWhereStatement('log_visit', 'visit_last_action_time'), 'log_visit.idvisit', false);
            $this->visitsSql = " AND %s.idvisit IN ({$visits['sql']})";
            $this->visitsBind = $visits['bind'];
        }
    }

    /**
     * The steps before and after the first time a visit came to a page.
     *
     * @return array{label: string, nb_visits: int, nb_entries: int, nb_exits: int, steps: array<int, array<int, array{label: string, nb_visits: int}>>}
     */
    public function getForPage(string $label): array
    {
        $result = ['label' => $label, 'nb_visits' => 0, 'nb_entries' => 0, 'nb_exits' => 0, 'steps' => []];
        $ids = $this->findPages($label);
        if (!$ids) {
            return $result;
        }

        $actions = self::ACTIONS;
        $arrivals = $this->logAggregator->generateQuery(
            "$actions.idvisit, MIN($actions.pageview_position) AS position",
            [$actions],
            $this->logAggregator->getWhereStatement($actions, 'server_time') . " AND $actions." . $this->column() . ' IN (' . implode(',', $ids) . ')' . sprintf($this->visitsSql, $actions),
            "$actions.idvisit",
            false
        );
        $arrivals['bind'] = array_merge($arrivals['bind'], $this->visitsBind);

        $steps = $this->countSteps($arrivals, -self::STEPS, self::STEPS);
        unset($steps[0]);

        return ['steps' => $this->toLists($steps, -self::STEPS, self::STEPS, [0])] + $this->countArrivals($arrivals, $steps) + $result;
    }

    /**
     * The steps before the first time a visit converted a goal, the page it converted on, and
     * for the pages most of them viewed on the way: how the visits to that page ended.
     *
     * @return array<string, mixed>
     */
    public function getForGoal(int $idGoal): array
    {
        $conversions = $this->logAggregator->generateQuery(
            'log_conversion.idvisit, MIN(log_conversion.server_time) AS converted_at',
            ['log_conversion'],
            $this->logAggregator->getWhereStatement('log_conversion', 'server_time') . ' AND log_conversion.idgoal = ' . $idGoal . sprintf($this->visitsSql, 'log_conversion'),
            'log_conversion.idvisit',
            false
        );
        $conversions['bind'] = array_merge($conversions['bind'], $this->visitsBind);

        // The page a visit was on when it converted is the last one it viewed until then.
        $log = Common::prefixTable(self::ACTIONS);
        $arrivals = [
            'sql' => "SELECT c.idvisit, MAX(l.pageview_position) AS position
                        FROM ({$conversions['sql']}) c
                        JOIN $log l ON l.idvisit = c.idvisit AND l.server_time <= c.converted_at
                       GROUP BY c.idvisit",
            'bind' => $conversions['bind'],
        ];

        $steps = $this->countSteps($arrivals, -self::STEPS_TO_GOAL, 0);
        $result = $this->countArrivals($arrivals, $steps);
        unset($result['nb_exits']);

        return $result + [
            'steps' => $this->toLists($steps, -self::STEPS_TO_GOAL, 0, []),
            'pages' => $result['nb_visits'] > 0 ? $this->getPagesOnTheWay($arrivals, $conversions) : [],
        ];
    }

    /**
     * @param array{sql: string, bind: array} $arrivals the visits with the position they arrived at
     * @param array<int, array<string, int>> $steps
     * @return array{nb_visits: int, nb_entries: int, nb_exits: int}
     */
    private function countArrivals(array $arrivals, array $steps): array
    {
        $visits = (int) $this->logAggregator->getDb()->fetchOne("SELECT COUNT(*) FROM ({$arrivals['sql']}) rebelpaths_arrivals", $arrivals['bind']);

        return [
            'nb_visits' => $visits,
            'nb_entries' => max(0, $visits - array_sum($steps[-1] ?? [])),
            'nb_exits' => max(0, $visits - array_sum($steps[1] ?? [])),
        ];
    }

    /**
     * The visits that viewed each page at each step from the position they arrived at.
     *
     * @param array{sql: string, bind: array} $arrivals
     * @return array<int, array<string, int>> step => label => visits
     */
    private function countSteps(array $arrivals, int $from, int $to): array
    {
        $rows = $this->logAggregator->getDb()->fetchAll(
            'SELECT CAST(l.pageview_position AS SIGNED) - CAST(a.position AS SIGNED) AS step, ' . Paths::getStepSql('l', $this->byUrl) . " AS id, COUNT(DISTINCT a.idvisit) AS nb_visits
               FROM ({$arrivals['sql']}) a
               JOIN " . Common::prefixTable(self::ACTIONS) . " l ON l.idvisit = a.idvisit
                AND l.pageview_position BETWEEN CAST(a.position AS SIGNED) + ($from) AND a.position + $to
               " . $this->joinActions('l') . '
              GROUP BY step, id
             HAVING id IS NOT NULL',
            $arrivals['bind']
        );

        return $this->byLabel($rows, 'step');
    }

    /**
     * For the pages most converting visits viewed until they converted: the visits that viewed
     * the page, those that converted after it, and those that did not convert and left on it.
     *
     * @param array{sql: string, bind: array} $arrivals
     * @param array{sql: string, bind: array} $conversions
     * @return array<int, array{label: string, nb_visits: int, nb_converted: int, nb_exits: int}>
     */
    private function getPagesOnTheWay(array $arrivals, array $conversions): array
    {
        $db = $this->logAggregator->getDb();
        $actions = self::ACTIONS;
        $rows = $db->fetchAll(
            'SELECT ' . Paths::getStepSql('l', $this->byUrl) . " AS id, COUNT(DISTINCT a.idvisit) AS nb_visits
               FROM ({$arrivals['sql']}) a
               JOIN " . Common::prefixTable($actions) . ' l ON l.idvisit = a.idvisit AND l.pageview_position <= a.position
               ' . $this->joinActions('l') . '
              GROUP BY id
             HAVING id IS NOT NULL AND id <> ' . Paths::SEARCH_ID,
            $arrivals['bind']
        );
        $converted = $this->byLabel($rows)[0] ?? [];
        arsort($converted);
        $converted = array_slice($converted, 0, self::MAX_PAGES_ON_THE_WAY, true);

        $labels = Paths::fetchLabels($db, array_column($rows, 'id'), $this->byUrl, $this->host);
        $ids = array_keys(array_intersect($labels, array_map('strval', array_keys($converted))));
        if (!$ids) {
            return [];
        }
        $in = ' IN (' . implode(',', array_map('intval', $ids)) . ')';

        $viewed = $this->logAggregator->generateQuery(
            "$actions." . $this->column() . " AS id, COUNT(DISTINCT $actions.idvisit) AS nb_visits",
            [$actions],
            $this->logAggregator->getWhereStatement($actions, 'server_time') . " AND $actions." . $this->column() . $in . sprintf($this->visitsSql, $actions),
            'id',
            false
        );
        $viewed = $this->byLabel($db->fetchAll($viewed['sql'], array_merge($viewed['bind'], $this->visitsBind)))[0] ?? [];

        $exit = 'log_visit.visit_exit_' . $this->column();
        $left = $this->logAggregator->generateQuery(
            "$exit AS id, COUNT(*) AS nb_visits",
            ['log_visit'],
            $this->logAggregator->getWhereStatement('log_visit', 'visit_last_action_time') . " AND $exit" . $in . sprintf($this->visitsSql, 'log_visit')
                . " AND log_visit.idvisit NOT IN (SELECT idvisit FROM ({$conversions['sql']}) rebelpaths_converted)",
            'id',
            false
        );
        $left = $this->byLabel($db->fetchAll($left['sql'], array_merge($left['bind'], $this->visitsBind, $conversions['bind'])))[0] ?? [];

        $pages = [];
        foreach ($converted as $label => $visits) {
            $pages[] = [
                'label' => (string) $label,
                // A visit that came to the page only after it converted is not among those that viewed it on the way.
                'nb_visits' => max($viewed[$label] ?? 0, $visits + ($left[$label] ?? 0)),
                'nb_converted' => $visits,
                'nb_exits' => $left[$label] ?? 0,
            ];
        }

        return $pages;
    }

    /**
     * Adds up the rows of pages that are shown with the same label.
     *
     * @param array<int, array<string, mixed>> $rows with "id", "nb_visits" and the column to group by
     * @return array<int, array<string, int>>
     */
    private function byLabel(array $rows, string $groupBy = ''): array
    {
        $labels = Paths::fetchLabels($this->logAggregator->getDb(), array_column($rows, 'id'), $this->byUrl, $this->host);
        $grouped = [];
        foreach ($rows as $row) {
            $group = $groupBy === '' ? 0 : (int) $row[$groupBy];
            $label = $labels[(int) $row['id']] ?? (string) $row['id'];
            $grouped[$group][$label] = ($grouped[$group][$label] ?? 0) + (int) $row['nb_visits'];
        }

        return $grouped;
    }

    /**
     * The most viewed pages of each step, and what is left of the step as one row without a label.
     *
     * @param array<int, array<string, int>> $steps
     * @param int[] $without
     * @return array<int, array<int, array{label: string, nb_visits: int}>>
     */
    private function toLists(array $steps, int $from, int $to, array $without): array
    {
        $lists = [];
        foreach (array_diff(range($from, $to), $without) as $step) {
            $pages = $steps[$step] ?? [];
            arsort($pages);
            $shown = array_slice($pages, 0, self::MAX_PAGES_OF_A_STEP, true);
            $lists[$step] = [];
            foreach ($shown as $label => $visits) {
                $lists[$step][] = ['label' => (string) $label, 'nb_visits' => $visits];
            }
            if (count($pages) > count($shown)) {
                $lists[$step][] = ['label' => '', 'nb_visits' => array_sum($pages) - array_sum($shown)];
            }
        }

        return $lists;
    }

    private function column(): string
    {
        return $this->byUrl ? 'idaction_url' : 'idaction_name';
    }

    private function joinActions(string $alias): string
    {
        $action = Common::prefixTable('log_action');

        return "LEFT JOIN $action rebelpaths_name ON rebelpaths_name.idaction = $alias.idaction_name"
            . ($this->byUrl ? " LEFT JOIN $action rebelpaths_url ON rebelpaths_url.idaction = $alias.idaction_url" : '');
    }

    /**
     * The pages that are shown with this label. Names are stored encoded for HTML.
     *
     * @return int[]
     */
    private function findPages(string $label): array
    {
        $label = Common::sanitizeInputValue($label);
        $names = [$label];
        if ($this->byUrl && $this->host !== '' && str_starts_with($label, '/')) {
            $names = [$this->host . $label, 'www.' . $this->host . $label];
            if ($label === '/') {
                array_push($names, $this->host, 'www.' . $this->host);
            }
        }

        $ids = Db::fetchAll(
            'SELECT idaction FROM ' . Common::prefixTable('log_action') . '
              WHERE type = ? AND hash IN (' . implode(',', array_fill(0, count($names), 'CRC32(?)')) . ') AND name IN (' . Common::getSqlStringFieldsArray($names) . ')',
            array_merge([$this->byUrl ? Action::TYPE_PAGE_URL : Action::TYPE_PAGE_TITLE], $names, $names)
        );

        return array_map('intval', array_column($ids, 'idaction'));
    }
}
