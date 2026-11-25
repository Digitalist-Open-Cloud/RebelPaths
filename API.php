<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths;

use Piwik\Archive;
use Piwik\Common;
use Piwik\DataTable;
use Piwik\Piwik;
use Piwik\Plugins\RebelPaths\RecordBuilders\Paths;

/**
 * The paths visitors take through a website.
 *
 * @method static \Piwik\Plugins\RebelPaths\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    /**
     * The first pages of the visits of a period by address, and under each the pages that followed.
     * A row has the visits that came this far and how many of them ended there.
     *
     * @param int|string $idSite
     * @param string $period
     * @param string $date
     * @param string|false $segment
     * @param bool $expanded
     * @param bool $flat
     * @param int|false $idSubtable
     * @return DataTable|DataTable\Map
     */
    public function getPaths($idSite, $period, $date, $segment = false, $expanded = false, $flat = false, $idSubtable = false)
    {
        return $this->getTable(Paths::RECORD_URLS, $idSite, $period, $date, $segment, $expanded, $flat, $idSubtable);
    }

    /**
     * As getPaths, with the pages by their title.
     *
     * @param int|string $idSite
     * @param string $period
     * @param string $date
     * @param string|false $segment
     * @param bool $expanded
     * @param bool $flat
     * @param int|false $idSubtable
     * @return DataTable|DataTable\Map
     */
    public function getPathsByTitle($idSite, $period, $date, $segment = false, $expanded = false, $flat = false, $idSubtable = false)
    {
        return $this->getTable(Paths::RECORD_TITLES, $idSite, $period, $date, $segment, $expanded, $flat, $idSubtable);
    }

    /**
     * What visits viewed in the two steps before and the two steps after they first came to a
     * page. The page is named as the paths show it: an address such as "/pricing", or a title.
     *
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @param string $page
     * @param bool $byTitle
     * @param string|false $segment
     * @return array the visits that came to the page, how many of them started and ended there,
     *               and under "steps" the pages of each step from -2 to 2 with their visits
     */
    public function getPagesAroundPage(int $idSite, $period, $date, string $page, bool $byTitle = false, $segment = false): array
    {
        Piwik::checkUserHasViewAccess($idSite);

        $around = (new PagesAround())->get($idSite, (string) $period, (string) $date, Common::unsanitizeInputValue($page), !$byTitle, Common::unsanitizeInputValue((string) $segment));

        $translate = function (string $label): string {
            return $label === Paths::SEARCH ? Piwik::translate(Paths::SEARCH) : Common::unsanitizeInputValue($label);
        };
        foreach ($around['steps'] as &$pages) {
            foreach ($pages as &$step) {
                $step['label'] = $translate($step['label']);
            }
        }

        return $around;
    }

    /**
     * @param mixed $idSite
     * @param mixed $period
     * @param mixed $date
     * @param mixed $segment
     * @param mixed $expanded
     * @param mixed $flat
     * @param mixed $idSubtable
     * @return DataTable|DataTable\Map
     */
    private function getTable(string $record, $idSite, $period, $date, $segment, $expanded, $flat, $idSubtable)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $table = Archive::createDataTableFromArchive($record, $idSite, $period, $date, $segment, $expanded, $flat, $idSubtable);

        $translate = ['label', function ($label) {
            return $label === Paths::SEARCH ? Piwik::translate(Paths::SEARCH) : $label;
        }];
        $table->filter('ColumnCallbackReplace', $translate);
        $table->filterSubtables('ColumnCallbackReplace', $translate);

        return $table;
    }
}
