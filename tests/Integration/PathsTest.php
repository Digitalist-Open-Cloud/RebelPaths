<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths\tests\Integration;

use Piwik\API\Request;
use Piwik\DataTable;
use Piwik\NoAccessException;
use Piwik\Plugins\API\API as ApiApi;
use Piwik\Plugins\RebelPaths\API;
use Piwik\Plugins\RebelPaths\Widgets\GetPathsOverview;
use Piwik\Plugins\SitesManager\API as SitesManagerApi;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group RebelPaths
 * @group Plugins
 */
class PathsTest extends IntegrationTestCase
{
    private const DAY = '2024-03-05';

    /**
     * @var int
     */
    private $visits = 0;

    public function setUp(): void
    {
        parent::setUp();

        FakeAccess::clearAccess(true);
        Fixture::loadAllTranslations();
        Fixture::createSuperUser();
        Fixture::createWebsite('2024-01-01 00:00:00', 0, 'Paths', 'http://www.example.org');
        Fixture::createWebsite('2024-01-01 00:00:00');
        $this->visits = 0;
        $_GET = [];
    }

    public function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    public function testThePathsOfVisitsAreCountedStepByStep()
    {
        $this->trackFourVisits();

        $this->assertSame([
            '/' => [3, 0, [
                '/pricing' => [2, 1, [
                    '/signup?plan=a' => [1, 1],
                ]],
                '/about' => [1, 1],
            ]],
            '/pricing' => [1, 1],
        ], $this->tree($this->report('getPaths', ['expanded' => 1])));
    }

    public function testPathsAreAlsoCountedByPageTitle()
    {
        $this->trackFourVisits();

        $this->assertSame([
            'Start' => [3, 0, [
                'Pricing' => [2, 1, [
                    'Sign up' => [1, 1],
                ]],
                'About' => [1, 1],
            ]],
            'Pricing' => [1, 1],
        ], $this->tree($this->report('getPathsByTitle', ['expanded' => 1])));
    }

    public function testTheShareThatLeftIsWorkedOutForAStep()
    {
        $this->trackFourVisits();

        $start = $this->report('getPaths', ['expanded' => 1])->getRowFromLabel('/');
        $pricing = $start->getSubtable()->getRowFromLabel('/pricing');

        $this->assertEquals(0, $start->getColumn('path_exit_rate'));
        $this->assertEquals(0.5, $pricing->getColumn('path_exit_rate'));
    }

    public function testAFlatTableListsWholePaths()
    {
        $this->trackFourVisits();

        $paths = $this->report('getPaths', ['flat' => 1])->getColumn('nb_visits');
        $labels = $this->report('getPaths', ['flat' => 1])->getColumn('label');

        $this->assertEquals(['/ → /pricing → /signup?plan=a' => 1, '/ → /about' => 1, '/pricing' => 1], array_combine($labels, $paths));
    }

    public function testAPathHasNoMoreStepsThanTheLimitAndKnowsWhoWentOn()
    {
        $this->visit(['/1', '/2', '/3', '/4', '/5', '/6', '/7']);
        $this->visit(['/1', '/2', '/3', '/4', '/5']);

        $labels = $this->report('getPaths', ['flat' => 1])->getColumn('label');
        $this->assertSame(['/1 → /2 → /3 → /4 → /5'], $labels);

        $last = $this->report('getPaths', ['flat' => 1])->getFirstRow();
        $this->assertEquals([2, 1], [$last->getColumn('nb_visits'), $last->getColumn('nb_path_exits')]);
    }

    public function testASearchIsAStepAndWhatIsNotAPageIsNot()
    {
        $tracker = $this->visit(['/']);
        Fixture::checkResponse($tracker->doTrackEvent('Video', 'Play', 'Intro'));
        Fixture::checkResponse($tracker->doTrackAction('http://www.example.org/files/price-list.pdf', 'download'));
        Fixture::checkResponse($tracker->doTrackSiteSearch('prices'));
        Fixture::checkResponse($tracker->doTrackEvent('Search', 'Filter', 'Cheapest'));
        $this->view($tracker, '/pricing');

        $this->assertSame(['/ → Site search → /pricing'], $this->report('getPaths', ['flat' => 1])->getColumn('label'));
        $this->assertSame(['Start → Site search → Pricing'], $this->report('getPathsByTitle', ['flat' => 1])->getColumn('label'));
    }

    public function testAPageThatIsLoadedAgainIsAStep()
    {
        $this->visit(['/', '/pricing', '/pricing']);

        $this->assertSame(['/ → /pricing → /pricing'], $this->report('getPaths', ['flat' => 1])->getColumn('label'));
    }

    public function testAPageOfAnotherHostKeepsItsHost()
    {
        $this->visit(['/', 'http://shop.example.org/cart']);

        $this->assertSame(['/ → shop.example.org/cart'], $this->report('getPaths', ['flat' => 1])->getColumn('label'));
    }

    public function testAWebsiteWithAPortInItsAddressHasShortLabelsToo()
    {
        SitesManagerApi::getInstance()->updateSite(1, urls: ['http://localhost:8088']);
        $this->visit(['http://localhost:8088/start', 'http://localhost:8088/next']);

        $this->assertSame(['/start → /next'], $this->report('getPaths', ['flat' => 1])->getColumn('label'));
    }

    public function testASegmentNarrowsThePathsToItsVisits()
    {
        $this->trackFourVisits();

        $paths = $this->report('getPaths', ['flat' => 1, 'segment' => 'pageUrl=@' . urlencode('/signup')]);

        $this->assertSame(['/ → /pricing → /signup?plan=a'], $paths->getColumn('label'));
        $this->assertSame([1], array_map('intval', $paths->getColumn('nb_visits')));
    }

    public function testTheDaysOfAWeekAreAddedUp()
    {
        $this->trackFourVisits();
        $this->visit(['/', '/pricing'], '2024-03-06');
        $this->visit(['/contact'], '2024-03-06');

        $this->assertEquals([
            '/' => [4, 0, [
                '/pricing' => [3, 2, [
                    '/signup?plan=a' => [1, 1],
                ]],
                '/about' => [1, 1],
            ]],
            '/pricing' => [1, 1],
            '/contact' => [1, 1],
        ], $this->tree($this->report('getPaths', ['expanded' => 1, 'period' => 'week'])));
    }

    public function testTheNextStepsOfARowAreLoadedByItsSubtable()
    {
        $this->trackFourVisits();

        $start = $this->report('getPaths')->getRowFromLabel('/');
        $next = $this->report('getPaths', ['idSubtable' => $start->getIdSubDataTable()]);

        $this->assertSame(['/pricing' => 2, '/about' => 1], array_map('intval', array_combine($next->getColumn('label'), $next->getColumn('nb_visits'))));
    }

    public function testAStepCanBeFollowedOverTime()
    {
        $this->trackFourVisits();
        $this->visit(['/', '/pricing'], '2024-03-06');

        $evolution = ApiApi::getInstance()->getRowEvolution(1, 'day', '2024-03-05,2024-03-06', 'RebelPaths', 'getPaths', '/');

        $this->assertEquals(3, $evolution['reportData']->getTable(self::DAY)->getFirstRow()->getColumn('nb_visits'));
        $this->assertEquals(1, $evolution['reportData']->getTable('2024-03-06')->getFirstRow()->getColumn('nb_visits'));
    }

    public function testThePageListsTheMostCommonWholePathsAndGivesTheExplorerItsSteps()
    {
        $this->trackFourVisits();
        $this->visit(['/', '/pricing']);
        $this->view($this->visit(['/<b>']), '/a&b');
        $_GET = ['idSite' => '1', 'period' => 'day', 'date' => self::DAY];

        $page = (new GetPathsOverview())->render();

        // The path most visits ended on comes first.
        $this->assertSame(1, preg_match('~rebelPathsChip">/</span>.*?rebelPathsChip">/pricing</span>.*?left</span>.*?2 visits~s', $page));
        $this->assertLessThan(strpos($page, '/signup?plan=a</span>'), strpos($page, '2 visits'));
        $this->assertStringContainsString('<span class="rebelPathsChip">/&lt;b&gt;</span>', $page);
        $this->assertStringContainsString('<span class="rebelPathsChip">/a&amp;b</span>', $page);

        preg_match('/data-steps="([^"]*)"/', $page, $match);
        $steps = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
        $this->assertSame(['/', 4, 0], [$steps['urls'][0]['l'], $steps['urls'][0]['v'], $steps['urls'][0]['x']]);
        $this->assertSame(['/pricing', 3, 2], [$steps['urls'][0]['c'][0]['l'], $steps['urls'][0]['c'][0]['v'], $steps['urls'][0]['c'][0]['x']]);
        $this->assertSame('Start', $steps['titles'][0]['l']);
        $this->assertSame(0, preg_match('/RebelPaths_[A-Z]/', $page));
    }

    public function testAUserWithoutAccessToTheWebsiteDoesNotGetThePage()
    {
        FakeAccess::clearAccess(false, [], [2]);
        $_GET = ['idSite' => '1', 'period' => 'day', 'date' => self::DAY];

        $this->expectException(NoAccessException::class);
        (new GetPathsOverview())->render();
    }

    public function testThePagesBeforeAndAfterAPageAreCounted()
    {
        $this->trackFourVisits();
        $this->visit(['/about', '/', '/pricing', '/signup?plan=a', '/done']);

        $around = $this->around('/pricing');

        $this->assertSame(['/pricing', 4, 1, 2], [$around['label'], $around['nb_visits'], $around['nb_entries'], $around['nb_exits']]);
        $this->assertSame([
            -2 => [['label' => '/about', 'nb_visits' => 1]],
            -1 => [['label' => '/', 'nb_visits' => 3]],
            1 => [['label' => '/signup?plan=a', 'nb_visits' => 2]],
            2 => [['label' => '/done', 'nb_visits' => 1]],
        ], $around['steps']);
    }

    public function testThePagesAroundAPageCountFromTheFirstTimeAVisitCameToIt()
    {
        $tracker = $this->visit(['/', '/pricing', '/about', '/pricing', '/signup?plan=a']);
        Fixture::checkResponse($tracker->doTrackEvent('Video', 'Play', 'Intro'));

        $around = $this->around('/pricing');

        $this->assertSame(1, $around['nb_visits']);
        $this->assertSame([['label' => '/about', 'nb_visits' => 1]], $around['steps'][1]);
        $this->assertSame([['label' => '/pricing', 'nb_visits' => 1]], $around['steps'][2]);
    }

    public function testThePagesAroundAPageCanBeReadByTitleAndForASegment()
    {
        $this->trackFourVisits();

        $byTitle = $this->around('Pricing', ['byTitle' => 1]);
        $this->assertSame([3, [['label' => 'Start', 'nb_visits' => 2]], [['label' => 'Sign up', 'nb_visits' => 1]]], [$byTitle['nb_visits'], $byTitle['steps'][-1], $byTitle['steps'][1]]);

        $segmented = $this->around('/pricing', ['segment' => 'pageUrl=@' . urlencode('/signup')]);
        $this->assertSame([1, 0, 0], [$segmented['nb_visits'], $segmented['nb_entries'], $segmented['nb_exits']]);
    }

    public function testAPageThatWasNotViewedHasNothingAroundIt()
    {
        $this->trackFourVisits();

        $this->assertSame(0, $this->around("/nothing' OR 1")['nb_visits']);
        $this->assertSame(0, $this->around('/pricing', ['idSite' => 2])['nb_visits']);
    }

    public function testAWebsiteHasOnlyItsOwnPaths()
    {
        $this->trackFourVisits();

        $this->assertSame(0, $this->report('getPaths', ['idSite' => 2])->getRowsCount());
    }

    public function testAUserWithoutAccessToTheWebsiteGetsNoPaths()
    {
        $this->trackFourVisits();
        FakeAccess::clearAccess(false, [], [2]);

        foreach (['getPaths', 'getPathsByTitle', 'getPagesAroundPage'] as $method) {
            try {
                API::getInstance()->$method(1, 'day', self::DAY, '/pricing');
                $this->fail($method . ' was allowed.');
            } catch (NoAccessException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function trackFourVisits(): void
    {
        $this->visit(['/', '/pricing', '/signup?plan=a']);
        $this->visit(['/', '/pricing']);
        $this->visit(['/', '/about']);
        $this->visit(['/pricing']);
    }

    private const TITLES = ['/' => 'Start', '/pricing' => 'Pricing', '/signup?plan=a' => 'Sign up', '/about' => 'About'];

    /**
     * A visit that views the given pages, a minute apart.
     *
     * @param string[] $pages
     */
    private function visit(array $pages, string $day = self::DAY): \MatomoTracker
    {
        $tracker = Fixture::getTracker(1, $day . ' 10:00:00', true, true);
        $tracker->setTokenAuth(Fixture::getTokenAuth());
        $tracker->setNewVisitorId();
        // Matomo takes requests from one address for one visit.
        $tracker->setIp('10.0.0.' . (1 + $this->visits++));
        foreach ($pages as $minute => $page) {
            $tracker->setForceVisitDateTime(sprintf('%s 10:%02d:00', $day, $minute));
            $this->view($tracker, $page);
        }
        $tracker->setForceVisitDateTime($day . ' 10:20:00');

        return $tracker;
    }

    private function view(\MatomoTracker $tracker, string $page): void
    {
        $tracker->setUrl(str_starts_with($page, 'http') ? $page : 'http://www.example.org' . $page);
        Fixture::checkResponse($tracker->doTrackPageView(self::TITLES[$page] ?? 'Page ' . $page));
    }

    private function report(string $method, array $extra = []): DataTable
    {
        return Request::processRequest('RebelPaths.' . $method, $extra + ['idSite' => 1, 'period' => 'day', 'date' => self::DAY, 'format_metrics' => 0]);
    }

    private function around(string $page, array $extra = []): array
    {
        return Request::processRequest('RebelPaths.getPagesAroundPage', $extra + ['idSite' => 1, 'period' => 'day', 'date' => self::DAY, 'page' => $page]);
    }

    /**
     * @return array<string, array> label => [visits, visits that left, next steps when there are any]
     */
    private function tree(DataTable $table): array
    {
        $tree = [];
        foreach ($table->getRows() as $row) {
            $node = [(int) $row->getColumn('nb_visits'), (int) $row->getColumn('nb_path_exits')];
            if ($row->getSubtable()) {
                $node[] = $this->tree($row->getSubtable());
            }
            $tree[$row->getColumn('label')] = $node;
        }

        return $tree;
    }

    public function provideContainerConfig()
    {
        return ['Piwik\Access' => new FakeAccess()];
    }
}
