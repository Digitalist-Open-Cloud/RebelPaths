<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths;

use Exception;
use Piwik\API\Request;
use Piwik\Archive;
use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\Piwik;
use Piwik\Plugin\Manager;
use Piwik\Plugins\RebelPaths\RecordBuilders\Paths;

/**
 * The paths visitors take through a website.
 *
 * @method static \Piwik\Plugins\RebelPaths\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    // Of the plugin RebelFunnels, which may not be there.
    public const FUNNEL_COUNTER = 'Piwik\Plugins\RebelFunnels\Archiving\FunnelCounter';

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
     * page. The page is named as the paths show it, an address such as "/pricing" or a title, or by
     * its whole address.
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

        $around = (new PagesAround($idSite, (string) $period, (string) $date, !$byTitle, Common::unsanitizeInputValue((string) $segment)))
            ->getForPage(Common::unsanitizeInputValue($page));
        $around['steps'] = $this->toReadableSteps($around['steps']);
        $around['label'] = Common::unsanitizeInputValue($around['label']);

        return $around;
    }

    /**
     * What visits viewed in the three steps before they first converted a goal and the page they
     * converted on. Under "pages": for the pages most of them viewed on the way, the visits that
     * viewed the page, those that converted after it and those that left the website on it
     * without converting.
     *
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @param int $idGoal the ID of a goal, or 0 for ecommerce orders
     * @param bool $byTitle
     * @param string|false $segment
     * @return array
     */
    public function getPagesBeforeGoal(int $idSite, $period, $date, int $idGoal, bool $byTitle = false, $segment = false): array
    {
        Piwik::checkUserHasViewAccess($idSite);

        $before = (new PagesAround($idSite, (string) $period, (string) $date, !$byTitle, Common::unsanitizeInputValue((string) $segment)))
            ->getForGoal($idGoal);
        $before['steps'] = $this->toReadableSteps($before['steps']);
        $before['pages'] = $this->toReadableSteps([$before['pages']])[0];

        return $before;
    }

    /**
     * As getPagesBeforeGoal, for the visits that completed a funnel of the plugin RebelFunnels:
     * the steps before they reached its last step, and the page they reached it on.
     *
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @param int $idFunnel
     * @param bool $byTitle
     * @param string|false $segment
     * @return array
     */
    public function getPagesBeforeFunnel(int $idSite, $period, $date, int $idFunnel, bool $byTitle = false, $segment = false): array
    {
        Piwik::checkUserHasViewAccess($idSite);
        if (!self::canUseFunnels()) {
            throw new Exception(Piwik::translate('RebelPaths_ErrorNoFunnels'));
        }

        $funnel = Request::processRequest('RebelFunnels.getFunnel', ['idSite' => $idSite, 'idFunnel' => $idFunnel], []);
        $before = (new PagesAround($idSite, (string) $period, (string) $date, !$byTitle, Common::unsanitizeInputValue((string) $segment)))
            ->getForFunnel($funnel);
        $before['steps'] = $this->toReadableSteps($before['steps']);
        $before['pages'] = $this->toReadableSteps([$before['pages']])[0];

        return $before;
    }

    /**
     * Whether the plugin RebelFunnels is there and tells which visits completed a funnel.
     *
     * @internal
     */
    public static function canUseFunnels(): bool
    {
        return Manager::getInstance()->isPluginActivated('RebelFunnels')
            && method_exists(StaticContainer::get(self::FUNNEL_COUNTER), 'getCompletions');
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $steps
     * @return array<int, array<int, array<string, mixed>>> with labels as they are shown
     */
    private function toReadableSteps(array $steps): array
    {
        foreach ($steps as &$pages) {
            foreach ($pages as &$page) {
                $page['label'] = $page['label'] === Paths::SEARCH ? Piwik::translate(Paths::SEARCH) : Common::unsanitizeInputValue($page['label']);
            }
        }

        return $steps;
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
