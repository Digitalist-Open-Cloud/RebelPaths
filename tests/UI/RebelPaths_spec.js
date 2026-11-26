describe('RebelPaths', function () {
    this.fixture = 'Piwik\\Plugins\\RebelPaths\\tests\\Fixtures\\SiteWithPaths';

    const params = 'idSite=1&period=day&date=2024-03-05';

    before(function () {
        testEnvironment.pluginsToLoad = ['RebelPaths'];
        testEnvironment.save();
    });

    async function open(subcategory, selector) {
        await page.goto(`?module=CoreHome&action=index&${params}#?${params}&category=RebelPaths_Paths&subcategory=RebelPaths_${subcategory}`);
        await page.waitForSelector(selector);
        await page.waitForNetworkIdle();
    }

    // The paths that are shown, each as its pages and how many visits took it.
    function paths() {
        return page.evaluate(() => [...document.querySelectorAll('.rebelPathsPath')]
            .filter((path) => path.offsetParent !== null)
            .map((path) => [...path.querySelectorAll('.rebelPathsChip')].map((chip) => chip.textContent.trim()).join(' → ')
                + ': ' + path.querySelector('.rebelPathsCount').textContent.trim()));
    }

    // The columns of a view, each as the texts of its rows.
    function columns(box) {
        return page.evaluate((selector) => [...document.querySelectorAll(`${selector} .rebelPathsColumn`)]
            .map((column) => [...column.querySelectorAll('.rebelPathsStepRow, .rebelPathsAroundCentre, .rebelPathsStartedHere, .rebelPathsLeftAfter, .rebelPathsHint')]
                .map((row) => row.textContent.replace(/\s+/g, ' ').trim())), box);
    }

    it('should have a page for each view in the menu, with the icon of the plugin', async function () {
        await open('TopPaths', '.rebelPathsPath');

        const menu = await page.evaluate(() => {
            const category = document.querySelector('#secondNavBar .rebelPathsIcon').closest('li');
            // The first link is the one that opens the category for screen readers.
            return [...category.querySelectorAll('ul a')].map((link) => link.textContent.trim()).slice(1);
        });

        expect(menu).to.deep.equal(['Top paths', 'Flow', 'Step explorer', 'Around a page', 'On the way to a goal']);
    });

    it('should list the most common whole paths', async function () {
        expect(await paths()).to.have.members([
            '/ → /pricing → /signup → left: 1 visit',
            '/ → /pricing → left: 1 visit',
            '/ → /about → left: 1 visit',
            '/pricing → left: 1 visit',
        ]);
    });

    it('should list the paths by page title when asked', async function () {
        await page.click('.rebelPathsBy[data-by="titles"]');

        expect(await paths()).to.include('Start → Pricing → Sign up → left: 1 visit');
    });

    it('should keep the choice of page titles on the next page', async function () {
        await open('Flow', '.rebelPathsFlowNode');

        expect(await page.evaluate(() => document.querySelector('.rebelPathsFlowNode title').textContent)).to.equal('Start: 3 visits, 0% left');
        await page.click('.rebelPathsBy[data-by="urls"]');
    });

    it('should draw the flow with a bar for each page of a step and a band for each move', async function () {

        const flow = await page.evaluate(() => ({
            pages: [...document.querySelectorAll('.rebelPathsFlowNode')]
                .map((node) => `${node.getAttribute('data-step')} ${node.querySelector('title').textContent}`),
            bands: document.querySelectorAll('.rebelPathsFlowLink').length,
        }));

        expect(flow.pages).to.deep.equal([
            '1 /: 3 visits, 0% left',
            '1 /pricing: 1 visit, 100% left',
            '2 /pricing: 2 visits, 50% left',
            '2 /about: 1 visit, 100% left',
            '3 /signup: 1 visit, 100% left',
        ]);
        expect(flow.bands).to.equal(3);
    });

    it('should light up the bands of a page that is clicked', async function () {
        await page.click('.rebelPathsFlowNode[data-step="2"]');

        expect(await page.evaluate(() => document.querySelectorAll('.rebelPathsFlowLink.active').length)).to.equal(2);
    });

    it('should open the explorer on the page most visits start on', async function () {
        await open('StepExplorer', '.rebelPathsExplorer .rebelPathsStep');

        expect((await columns('.rebelPathsExplorer')).slice(0, 3)).to.deep.equal([
            ['/3', '/pricing1'],
            ['/pricing2', '/about1'],
            ['Pick a page to the left.'],
        ]);
    });

    it('should show where visits went when a page is picked, and how many left', async function () {
        await page.click('.rebelPathsColumn:nth-child(2) .rebelPathsStepPick');

        expect((await columns('.rebelPathsExplorer')).slice(1, 4)).to.deep.equal([
            ['/pricing2', '/about1'],
            ['/signup1', '1 left after /pricing'],
            ['Pick a page to the left.'],
        ]);

        await page.click('.rebelPathsColumn:nth-child(3) .rebelPathsStepPick');
        expect((await columns('.rebelPathsExplorer'))[3]).to.deep.equal(['1 left after /signup']);
    });

    it('should explore by page title when asked', async function () {
        await page.click('.rebelPathsBy[data-by="titles"]');

        expect((await columns('.rebelPathsExplorer'))[0]).to.deep.equal(['Start3', 'Pricing1']);
        await page.click('.rebelPathsBy[data-by="urls"]');
    });

    it('should show what visits viewed before and after a page that is picked', async function () {
        await open('AroundAPage', '.rebelPathsAroundCentre');
        await page.select('.rebelPathsAroundPage', '/pricing');
        await page.waitForFunction(() => document.querySelector('.rebelPathsAroundCentre')?.textContent.startsWith('/pricing'));

        expect(await columns('.rebelPathsAround')).to.deep.equal([
            [],
            ['/2', '1 started here'],
            ['/pricing3Visits'],
            ['/signup1', '2 left here'],
            [],
        ]);
    });

    it('should look around another page when it is clicked', async function () {
        await page.click('.rebelPathsAround .rebelPathsStepPick');
        await page.waitForFunction(() => document.querySelector('.rebelPathsAroundCentre')?.textContent.startsWith('/3'));

        expect((await columns('.rebelPathsAround'))[3]).to.have.members(['/pricing2', '/about1']);
        expect(await page.evaluate(() => document.querySelector('.rebelPathsAroundPage').value)).to.equal('/');
    });

    it('should show the steps before a goal was converted', async function () {
        await open('ToAGoal', '.rebelPathsToGoal .rebelPathsAroundCentre');

        expect(await columns('.rebelPathsToGoal')).to.deep.equal([[], ['/1'], ['/pricing1'], ['/signup1'], ['Signed up1visits converted']]);
    });

    it('should show how the visits to the pages on the way ended', async function () {
        const rows = await page.evaluate(() => [...document.querySelectorAll('.rebelPathsWay')]
            .map((row) => `${row.querySelector('.rebelPathsWayLabel').textContent} | ${row.querySelector('.rebelPathsWayNumbers').textContent}`));

        expect(rows).to.have.members([
            '/ | Visits: 3. Converted: 33%. Left here: 0%.',
            '/pricing | Visits: 3. Converted: 33%. Left here: 67%.',
            '/signup | Visits: 1. Converted: 100%. Left here: 0%.',
        ]);
    });

    it('should look around a page of the goal page when it is clicked, by title when that was chosen', async function () {
        await page.click('.rebelPathsBy[data-by="titles"]');
        await page.waitForFunction(() => document.querySelector('.rebelPathsToGoal .rebelPathsStepLabel')?.textContent === 'Start');
        await page.click('.rebelPathsToGoal .rebelPathsColumn:nth-child(3) .rebelPathsStepPick');
        await page.waitForFunction(() => document.querySelector('.rebelPathsAround .rebelPathsAroundCentre')?.textContent.startsWith('Pricing'));

        expect((await columns('.rebelPathsAround'))[1]).to.deep.equal(['Start2', '1 started here']);
        await page.click('.rebelPathsBy[data-by="urls"]');
    });

    it('should open the page around a page from a row of the pages report', async function () {
        await page.goto(`?module=CoreHome&action=index&${params}#?${params}&category=General_Actions&subcategory=General_Pages`);
        await page.waitForSelector('.dataTable[data-report="Actions.getPageUrls"] tbody tr');
        await page.waitForNetworkIdle();

        const row = await page.jQuery('.dataTable[data-report="Actions.getPageUrls"] tbody tr:contains(pricing):first');
        await row.hover();
        await (await row.$('a.actionRebelPaths')).click();
        await page.waitForFunction(() => document.querySelector('.rebelPathsAround .rebelPathsAroundCentre')?.textContent.startsWith('/pricing'));

        expect((await columns('.rebelPathsAround'))[3]).to.deep.equal(['/signup1', '2 left here']);
    });

    it('should open the page around a page by its title from a row of the page titles report', async function () {
        await page.goto(`?module=CoreHome&action=index&${params}#?${params}&category=General_Actions&subcategory=Actions_SubmenuPageTitles`);
        await page.waitForSelector('.dataTable[data-report="Actions.getPageTitles"] tbody tr');
        await page.waitForNetworkIdle();

        const row = await page.jQuery('.dataTable[data-report="Actions.getPageTitles"] tbody tr:contains(Pricing):first');
        await row.hover();
        await (await row.$('a.actionRebelPaths')).click();
        await page.waitForFunction(() => document.querySelector('.rebelPathsAround .rebelPathsAroundCentre')?.textContent.startsWith('Pricing'));

        expect((await columns('.rebelPathsAround'))[3]).to.deep.equal(['Sign up1', '2 left here']);
    });
});
