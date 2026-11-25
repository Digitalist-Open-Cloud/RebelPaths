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
use Piwik\Period\Factory as PeriodFactory;
use Piwik\Plugins\RebelPaths\RecordBuilders\Paths;
use Piwik\Segment;
use Piwik\Site;
use Piwik\Tracker\Action;

/**
 * What visits viewed in the steps before and after the first time they came to a page. It is
 * read from the logs when it is asked for.
 */
class PagesAround
{
    public const STEPS = 2;

    private const MAX_PAGES_OF_A_STEP = 8;

    /**
     * @return array{label: string, nb_visits: int, nb_entries: int, nb_exits: int, steps: array<int, array<int, array{label: string, nb_visits: int}>>}
     */
    public function get(int $idSite, string $period, string $date, string $label, bool $byUrl, string $segment): array
    {
        $result = ['label' => $label, 'nb_visits' => 0, 'nb_entries' => 0, 'nb_exits' => 0, 'steps' => []];
        $host = Paths::getHost(Site::getMainUrlFor($idSite));
        $ids = $this->findPages($label, $byUrl, $host);
        if (!$ids) {
            return $result;
        }

        $range = PeriodFactory::build($period, $date);
        $site = new Site($idSite);
        $logAggregator = new LogAggregator(new Parameters($site, $range, new Segment('', [$idSite])));
        $actions = 'log_link_visit_action';
        $where = $logAggregator->getWhereStatement($actions, 'server_time') . " AND $actions." . ($byUrl ? 'idaction_url' : 'idaction_name') . ' IN (' . implode(',', $ids) . ')';
        $bind = [];

        // A segment on pages would leave only the page views it matches, so it picks the visits instead.
        if ($segment !== '') {
            $segmented = new LogAggregator(new Parameters($site, $range, new Segment($segment, [$idSite], $range->getDateTimeStart(), $range->getDateTimeEnd())));
            $visits = $segmented->generateQuery('log_visit.idvisit', ['log_visit'], $segmented->getWhereStatement('log_visit', 'visit_last_action_time'), 'log_visit.idvisit', false);
            $where .= " AND $actions.idvisit IN ({$visits['sql']})";
            $bind = $visits['bind'];
        }

        // ponytail: the page views of the period are read for each page that is asked for. Archive the
        // most visited pages if this is too slow on a large website.
        $arrivals = $logAggregator->generateQuery("$actions.idvisit, MIN($actions.pageview_position) AS position", [$actions], $where, "$actions.idvisit", false);
        $arrivals['bind'] = array_merge($arrivals['bind'], $bind);

        $db = $logAggregator->getDb();
        $result['nb_visits'] = (int) $db->fetchOne("SELECT COUNT(*) FROM ({$arrivals['sql']}) rebelpaths_arrivals", $arrivals['bind']);

        $log = Common::prefixTable($actions);
        $action = Common::prefixTable('log_action');
        $rows = $db->fetchAll(
            "SELECT CAST(l.pageview_position AS SIGNED) - CAST(a.position AS SIGNED) AS step, " . Paths::getStepSql('l', $byUrl) . " AS id, COUNT(DISTINCT a.idvisit) AS nb_visits
               FROM ({$arrivals['sql']}) a
               JOIN $log l ON l.idvisit = a.idvisit AND l.pageview_position <> a.position
                AND l.pageview_position BETWEEN CAST(a.position AS SIGNED) - " . self::STEPS . ' AND a.position + ' . self::STEPS . "
               LEFT JOIN $action rebelpaths_name ON rebelpaths_name.idaction = l.idaction_name
               " . ($byUrl ? "LEFT JOIN $action rebelpaths_url ON rebelpaths_url.idaction = l.idaction_url" : '') . '
              GROUP BY step, id
             HAVING id IS NOT NULL
              ORDER BY nb_visits DESC',
            $arrivals['bind']
        );

        $labels = Paths::fetchLabels($db, array_column($rows, 'id'), $byUrl, $host);
        $steps = [];
        foreach ($rows as $row) {
            $name = $labels[(int) $row['id']] ?? (string) $row['id'];
            // Pages with the same label are one.
            $steps[(int) $row['step']][$name] = ($steps[(int) $row['step']][$name] ?? 0) + (int) $row['nb_visits'];
        }

        for ($step = -self::STEPS; $step <= self::STEPS; $step++) {
            if ($step === 0) {
                continue;
            }
            $pages = $steps[$step] ?? [];
            arsort($pages);
            $shown = array_slice($pages, 0, self::MAX_PAGES_OF_A_STEP, true);
            $result['steps'][$step] = [];
            foreach ($shown as $name => $visits) {
                $result['steps'][$step][] = ['label' => (string) $name, 'nb_visits' => $visits];
            }
            if (count($pages) > count($shown)) {
                // Without a label: the pages that are not shown.
                $result['steps'][$step][] = ['label' => '', 'nb_visits' => array_sum($pages) - array_sum($shown)];
            }
        }
        $result['nb_entries'] = max(0, $result['nb_visits'] - array_sum($steps[-1] ?? []));
        $result['nb_exits'] = max(0, $result['nb_visits'] - array_sum($steps[1] ?? []));

        return $result;
    }

    /**
     * The pages that are shown with this label. Names are stored encoded for HTML.
     *
     * @return int[]
     */
    private function findPages(string $label, bool $byUrl, string $host): array
    {
        $label = Common::sanitizeInputValue($label);
        $names = [$label];
        if ($byUrl && $host !== '' && str_starts_with($label, '/')) {
            $names = [$host . $label, 'www.' . $host . $label];
            if ($label === '/') {
                array_push($names, $host, 'www.' . $host);
            }
        }

        $in = Common::getSqlStringFieldsArray($names);
        $ids = Db::fetchAll(
            'SELECT idaction FROM ' . Common::prefixTable('log_action') . "
              WHERE type = ? AND hash IN (" . implode(',', array_fill(0, count($names), 'CRC32(?)')) . ") AND name IN ($in)",
            array_merge([$byUrl ? Action::TYPE_PAGE_URL : Action::TYPE_PAGE_TITLE], $names, $names)
        );

        return array_map('intval', array_column($ids, 'idaction'));
    }
}
