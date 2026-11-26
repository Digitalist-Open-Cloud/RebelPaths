<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths\tests\Integration;

use Exception;
use Piwik\API\Request;
use Piwik\Plugins\RebelFunnels\API as FunnelsApi;
use Piwik\Plugins\RebelPaths\API;
use Piwik\Plugins\RebelPaths\Widgets\GetToAGoal;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * What needs the plugin RebelFunnels. Without it these tests are skipped.
 *
 * @group RebelPaths
 * @group Plugins
 */
class FunnelsTest extends IntegrationTestCase
{
    private const DAY = '2024-03-05';
    private const CHECKOUT = [
        ['name' => 'Cart', 'rules' => [['type' => 'url', 'operator' => 'contains', 'value' => '/cart']]],
        ['name' => 'Thanks', 'rules' => [['type' => 'url', 'operator' => 'contains', 'value' => '/thanks']]],
    ];

    /**
     * @var int
     */
    private $visits = 0;

    public function setUp(): void
    {
        parent::setUp();

        if (!API::canUseFunnels()) {
            $this->markTestSkipped('The plugin RebelFunnels is not there, or it does not tell which visits completed a funnel.');
        }
        Fixture::loadAllTranslations();
        Fixture::createSuperUser();
        Fixture::createWebsite('2024-01-01 00:00:00', 0, 'Paths', 'http://example.org');
        Fixture::createWebsite('2024-01-01 00:00:00');
        $this->visits = 0;
        $_GET = [];
    }

    public function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);
        $fixture->extraPluginsToLoad = array_values(array_filter(['RebelFunnels'], function (string $plugin): bool {
            return is_dir(PIWIK_INCLUDE_PATH . '/plugins/' . $plugin);
        }));
    }

    public function testTheStepsBeforeAFunnelWasCompletedAndHowVisitsToThosePagesEndedAreCounted()
    {
        $idFunnel = FunnelsApi::getInstance()->addFunnel(1, 'Checkout', self::CHECKOUT);
        $this->visit(['/', '/shop', '/cart', '/shipping', '/thanks']);
        $this->visit(['/shop', '/cart', '/thanks']);
        $this->visit(['/', '/cart']);
        // Reaches the last step without the first, so it does not complete the funnel.
        $this->visit(['/shop', '/thanks']);

        $before = $this->before($idFunnel);

        $this->assertSame([2, 0], [$before['nb_visits'], $before['nb_entries']]);
        $this->assertSame([
            -3 => ['/shop' => 1],
            -2 => ['/cart' => 1, '/shop' => 1],
            -1 => ['/cart' => 1, '/shipping' => 1],
            0 => ['/thanks' => 2],
        ], array_map(function (array $pages): array {
            $visits = array_column($pages, 'nb_visits', 'label');
            ksort($visits);

            return $visits;
        }, $before['steps']));

        $pages = array_column($before['pages'], null, 'label');
        $this->assertSame(['label' => '/cart', 'nb_visits' => 3, 'nb_converted' => 2, 'nb_exits' => 1], $pages['/cart']);
        $this->assertSame(['label' => '/shop', 'nb_visits' => 3, 'nb_converted' => 2, 'nb_exits' => 0], $pages['/shop']);
        // One of the visits that left on the page did not complete the funnel.
        $this->assertSame(['label' => '/thanks', 'nb_visits' => 3, 'nb_converted' => 2, 'nb_exits' => 1], $pages['/thanks']);
    }

    public function testAFunnelCountsOnlyTheVisitsOfItsOwnSegmentAndOfTheOneAskedFor()
    {
        $idFunnel = FunnelsApi::getInstance()->addFunnel(1, 'Checkout', self::CHECKOUT, '', 'pageUrl=@' . urlencode('/shop'));
        $this->visit(['/shop', '/cart', '/thanks']);
        $this->visit(['/shop', '/cart', '/more', '/thanks']);
        $this->visit(['/', '/cart', '/thanks']);

        $this->assertSame(2, $this->before($idFunnel)['nb_visits']);
        $this->assertSame(1, $this->before($idFunnel, ['segment' => 'actions>=4'])['nb_visits']);
    }

    public function testAFunnelOfAnotherWebsiteIsNotRead()
    {
        $idFunnel = FunnelsApi::getInstance()->addFunnel(2, 'Checkout', self::CHECKOUT);

        $this->expectException(Exception::class);
        $this->before($idFunnel);
    }

    public function testThePageOffersTheFunnelsOfTheWebsiteNextToItsGoals()
    {
        $idFunnel = FunnelsApi::getInstance()->addFunnel(1, 'Checkout <fast>', self::CHECKOUT);
        $_GET = ['idSite' => '1', 'period' => 'day', 'date' => self::DAY];

        $page = (new GetToAGoal())->render();

        $this->assertStringContainsString('<option value="funnel:' . $idFunnel . '">Checkout &lt;fast&gt;</option>', $page);
    }

    private function before(int $idFunnel, array $extra = []): array
    {
        return Request::processRequest('RebelPaths.getPagesBeforeFunnel', $extra + ['idSite' => 1, 'period' => 'day', 'date' => self::DAY, 'idFunnel' => $idFunnel]);
    }

    /**
     * @param string[] $pages
     */
    private function visit(array $pages): void
    {
        $tracker = Fixture::getTracker(1, self::DAY . ' 10:00:00', true, true);
        $tracker->setTokenAuth(Fixture::getTokenAuth());
        $tracker->setNewVisitorId();
        // Matomo takes requests from one address for one visit.
        $tracker->setIp('10.0.0.' . (1 + $this->visits++));
        foreach ($pages as $minute => $page) {
            $tracker->setForceVisitDateTime(sprintf('%s 10:%02d:00', self::DAY, $minute));
            $tracker->setUrl('http://example.org' . $page);
            Fixture::checkResponse($tracker->doTrackPageView('Page ' . $page));
        }
    }
}
