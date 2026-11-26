<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths\tests\Fixtures;

use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Tests\Framework\Fixture;

/**
 * A website with four visits: three start on the start page, two of them go on to the pricing
 * page and one of those signs up. One starts on the pricing page.
 */
class SiteWithPaths extends Fixture
{
    public const DAY = '2024-03-05';

    private const TITLES = ['/' => 'Start', '/pricing' => 'Pricing', '/signup' => 'Sign up', '/about' => 'About'];

    public function setUp(): void
    {
        if (!self::siteCreated(1)) {
            self::createWebsite('2024-01-01 00:00:00', 0, 'Paths', 'http://example.org');
        }

        if (!GoalsApi::getInstance()->getGoals(1)) {
            GoalsApi::getInstance()->addGoal(1, 'Signed up', 'url', '/signup', 'contains');
        }

        $this->visit(1, ['/', '/pricing', '/signup']);
        $this->visit(2, ['/', '/pricing']);
        $this->visit(3, ['/', '/about']);
        $this->visit(4, ['/pricing']);
    }

    public function tearDown(): void
    {
    }

    /**
     * @param string[] $pages
     */
    private function visit(int $visit, array $pages): void
    {
        $tracker = self::getTracker(1, self::DAY . ' 10:00:00', true, true);
        $tracker->setTokenAuth(self::getTokenAuth());
        $tracker->setNewVisitorId();
        // Matomo takes requests from one address for one visit.
        $tracker->setIp('10.0.0.' . $visit);
        foreach ($pages as $minute => $page) {
            $tracker->setForceVisitDateTime(sprintf('%s 10:%02d:00', self::DAY, $minute));
            $tracker->setUrl('http://example.org' . $page);
            self::checkResponse($tracker->doTrackPageView(self::TITLES[$page]));
        }
    }
}
