<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths\RecordBuilders;

use Piwik\ArchiveProcessor;
use Piwik\ArchiveProcessor\Record;
use Piwik\ArchiveProcessor\RecordBuilder;
use Piwik\Common;
use Piwik\Config;
use Piwik\DataAccess\LogAggregator;
use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Tracker\Action;

/**
 * The paths of the visits of a period from their first page: a row for each first page, under it
 * a row for each page that followed, and so on.
 */
class Paths extends RecordBuilder
{
    public const RECORD_URLS = 'RebelPaths_urls';
    public const RECORD_TITLES = 'RebelPaths_titles';

    public const STEPS = 5;

    // The label of a step that is a search on the website. It is translated when a report is read.
    public const SEARCH = 'RebelPaths_SiteSearch';

    // Stands for a search among the IDs of pages. No page has an ID this high.
    public const SEARCH_ID = 4294967295;

    // ponytail: the most common paths of a day are kept and the rest of a step is "Others". Raise it,
    // or make it a setting, if a website with many pages loses too much to "Others".
    private const MAX_PATHS = 20000;
    private const MAX_NEXT_STEPS = 50;

    public function __construct()
    {
        $maxRows = (int) Config::getInstance()->General['datatable_archiving_maximum_rows_actions'];

        parent::__construct($maxRows ?: null, self::MAX_NEXT_STEPS, 'nb_visits');
    }

    public function getRecordMetadata(ArchiveProcessor $archiveProcessor): array
    {
        return [
            Record::make(Record::TYPE_BLOB, self::RECORD_URLS),
            Record::make(Record::TYPE_BLOB, self::RECORD_TITLES),
        ];
    }

    protected function aggregate(ArchiveProcessor $archiveProcessor): array
    {
        $logAggregator = $archiveProcessor->getLogAggregator();
        $host = self::getHost($archiveProcessor->getParams()->getSite()->getMainUrl());

        return [
            self::RECORD_URLS => $this->buildTable($logAggregator, true, $host),
            self::RECORD_TITLES => $this->buildTable($logAggregator, false, $host),
        ];
    }

    private function buildTable(LogAggregator $logAggregator, bool $byUrl, string $host): DataTable
    {
        $paths = $this->fetchPaths($logAggregator, $byUrl);
        $ids = array_unique(array_merge(...array_column($paths, 0) ?: [[]]));
        $steps = self::fetchLabels($logAggregator->getDb(), $ids, $byUrl, $host);

        // Pages with the same label are one step, so the tree is built by label.
        /** @var array{children: array<string, array<string, mixed>>} $root */
        $root = ['children' => []];
        $known = [];
        foreach ($paths as [$ids, $visits]) {
            $isExit = end($ids) === 0;
            if ($isExit) {
                array_pop($ids);
            }
            $parent = implode('/', array_slice($ids, 0, -1));
            // A path whose start was not among the most common ones has nowhere to go.
            if (!$ids || in_array(0, $ids, true) || ($isExit ? !isset($known[implode('/', $ids)]) : ($parent !== '' && !isset($known[$parent])))) {
                continue;
            }
            $known[implode('/', $ids)] = true;

            $node = &$root;
            foreach ($ids as $id) {
                $label = $steps[$id] ?? (string) $id;
                $node['children'][$label] ??= ['label' => $label, 'visits' => 0, 'exits' => 0, 'children' => []];
                $node = &$node['children'][$label];
            }
            $node[$isExit ? 'exits' : 'visits'] += $visits;
            unset($node);
        }

        return $this->toTable($root);
    }

    /**
     * Every start of a path with the visits that took it, the most common first. A path that ends
     * in 0 holds the visits that ended after the steps before it.
     *
     * @return array<int, array{0: int[], 1: int}>
     */
    private function fetchPaths(LogAggregator $logAggregator, bool $byUrl): array
    {
        $actions = 'log_link_visit_action';
        $from = [$actions, ['table' => 'log_action', 'tableAlias' => 'rebelpaths_name', 'joinOn' => "rebelpaths_name.idaction = $actions.idaction_name"]];
        if ($byUrl) {
            $from[] = ['table' => 'log_action', 'tableAlias' => 'rebelpaths_url', 'joinOn' => "rebelpaths_url.idaction = $actions.idaction_url"];
        }
        // An event has the position of the page it happened on, so the highest ID of a position is taken.
        $step = self::getStepSql($actions, $byUrl);

        // One step more than is shown tells which visits ended at the last step.
        $columns = [];
        $select = "$actions.idvisit";
        $order = 'nb DESC';
        for ($i = 1; $i <= self::STEPS + 1; $i++) {
            $columns[] = "s$i";
            $select .= ", COALESCE(MAX(CASE WHEN $actions.pageview_position = $i THEN $step END), 0) AS s$i";
            // A path comes before the paths that go on from it.
            $order .= $i > 1 ? ", (s$i IS NULL) DESC" : '';
        }
        $columns = implode(', ', $columns);
        $last = 's' . (self::STEPS + 1);

        $query = $logAggregator->generateQuery(
            $select,
            $from,
            $logAggregator->getWhereStatement($actions, 'server_time') . " AND $actions.pageview_position <= " . (self::STEPS + 1),
            "$actions.idvisit",
            false
        );

        // The rollup counts every start of a path. NULL is "any step" there, which is why the end of a visit is 0.
        $sql = "SELECT $columns, nb
                  FROM (SELECT $columns, COUNT(*) AS nb FROM ({$query['sql']}) rebelpaths_visits WHERE s1 > 0 GROUP BY $columns WITH ROLLUP) rebelpaths_paths
                 WHERE s1 IS NOT NULL AND ($last IS NULL OR $last = 0)
                 ORDER BY $order
                 LIMIT " . self::MAX_PATHS;

        $paths = [];
        foreach ($logAggregator->getDb()->fetchAll($sql, $query['bind']) as $row) {
            $ids = [];
            for ($i = 1; $i <= self::STEPS + 1 && $row["s$i"] !== null; $i++) {
                $ids[] = (int) $row["s$i"];
            }
            $paths[] = [$ids, (int) $row['nb']];
        }

        return $paths;
    }

    /**
     * The ID of the step a row of the actions table is: its page, the number that stands for a
     * search, or NULL for what is not a step. The actions are joined as rebelpaths_name, and by
     * address also as rebelpaths_url.
     */
    public static function getStepSql(string $actions, bool $byUrl): string
    {
        $page = $byUrl
            ? 'WHEN rebelpaths_url.type = ' . Action::TYPE_PAGE_URL . " THEN $actions.idaction_url"
            : 'WHEN rebelpaths_name.type = ' . Action::TYPE_PAGE_TITLE . " THEN $actions.idaction_name";

        return 'CASE WHEN rebelpaths_name.type = ' . Action::TYPE_SITE_SEARCH . ' THEN ' . self::SEARCH_ID . " $page END";
    }

    /**
     * The host of a website as it starts the stored address of its pages, with the port when it has one.
     */
    public static function getHost(string $mainUrl): string
    {
        $url = parse_url($mainUrl) ?: [];
        $host = ($url['host'] ?? '') . (isset($url['port']) ? ':' . $url['port'] : '');

        return (string) preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * @param \Piwik\DataAccess\ArchivingDbAdapter $db
     * @param int[] $ids
     * @return array<int, string> what a step is shown as, by its ID
     */
    public static function fetchLabels($db, array $ids, bool $byUrl, string $host): array
    {
        $labels = [self::SEARCH_ID => self::SEARCH];
        $ids = array_values(array_diff(array_map('intval', $ids), [0, self::SEARCH_ID]));

        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $db->fetchAll(
                'SELECT idaction, name FROM ' . Common::prefixTable('log_action') . ' WHERE idaction IN (' . Common::getSqlStringFieldsArray($chunk) . ')',
                $chunk
            );
            foreach ($rows as $row) {
                // A page of the website itself is shown without the name of the website.
                $name = preg_replace('/^www\./', '', $row['name']);
                $isOwn = $byUrl && $host !== '' && (strtolower($name) === $host || stripos($name, $host . '/') === 0);
                $labels[(int) $row['idaction']] = $isOwn ? (substr($name, strlen($host)) ?: '/') : $row['name'];
            }
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function toTable(array $node, int $visits = 0): DataTable
    {
        $table = new DataTable();
        foreach ($node['children'] as $child) {
            $row = new Row([Row::COLUMNS => ['label' => $child['label'], 'nb_visits' => $child['visits'], 'nb_path_exits' => $child['exits']]]);
            if ($child['children']) {
                $row->setSubtable($this->toTable($child, $child['visits'] - $child['exits']));
            }
            $table->addRow($row);
            $visits -= $child['visits'];
        }

        // The visits that went on to a step that is not among the most common paths.
        if ($visits > 0) {
            $table->addSummaryRow(new Row([Row::COLUMNS => ['label' => DataTable::LABEL_SUMMARY_ROW, 'nb_visits' => $visits, 'nb_path_exits' => 0]]));
        }

        return $table;
    }
}
