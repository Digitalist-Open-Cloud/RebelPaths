describe('RebelPaths', function () {
    this.fixture = 'Piwik\\Plugins\\RebelPaths\\tests\\Fixtures\\SiteWithPaths';

    const params = 'idSite=1&period=day&date=2024-03-05';
    const pageUrl = `?module=CoreHome&action=index&${params}#?${params}`
        + '&category=General_Actions&subcategory=RebelPaths_Paths';

    before(function () {
        testEnvironment.pluginsToLoad = ['RebelPaths'];
        testEnvironment.save();
    });

    // The paths that are shown, each as its pages and how many visits took it.
    function paths() {
        return page.evaluate(() => [...document.querySelectorAll('.rebelPathsPath')]
            .filter((path) => path.offsetParent !== null)
            .map((path) => [...path.querySelectorAll('.rebelPathsChip')].map((chip) => chip.textContent.trim()).join(' → ')
                + ': ' + path.querySelector('.rebelPathsCount').textContent.trim()));
    }

    // The steps of each column of the explorer, as "label visits".
    function columns() {
        return page.evaluate(() => [...document.querySelectorAll('.rebelPathsColumn')]
            .map((column) => [...column.querySelectorAll('.rebelPathsStepRow, .rebelPathsLeftAfter, .rebelPathsHint')]
                .map((row) => row.textContent.replace(/\s+/g, ' ').trim())));
    }

    it('should list the most common whole paths', async function () {
        await page.goto(pageUrl);
        await page.waitForSelector('.rebelPathsExplorer .rebelPathsStep');
        await page.waitForNetworkIdle();

        expect(await paths()).to.have.members([
            '/ → /pricing → /signup → left: 1 visit',
            '/ → /pricing → left: 1 visit',
            '/ → /about → left: 1 visit',
            '/pricing → left: 1 visit',
        ]);
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
        expect((await columns()).slice(0, 3)).to.deep.equal([
            ['/3', '/pricing1'],
            ['/pricing2', '/about1'],
            ['Pick a page to the left.'],
        ]);
    });

    it('should show where visits went when a page is picked, and how many left', async function () {
        await page.click('.rebelPathsColumn:nth-child(2) .rebelPathsStepPick');

        expect((await columns()).slice(1, 4)).to.deep.equal([
            ['/pricing2', '/about1'],
            ['/signup1', '1 left after /pricing'],
            ['Pick a page to the left.'],
        ]);

        await page.click('.rebelPathsColumn:nth-child(3) .rebelPathsStepPick');
        expect((await columns())[3]).to.deep.equal(['1 left after /signup']);
    });

    it('should show the paths by page title when asked', async function () {
        await page.click('.rebelPathsBy[data-by="titles"]');

        expect(await paths()).to.include('Start → Pricing → Sign up → left: 1 visit');
        expect((await columns())[0]).to.deep.equal(['Start3', 'Pricing1']);
    });

    // The columns around the picked page, each as its texts.
    function around() {
        return page.evaluate(() => [...document.querySelectorAll('.rebelPathsAround .rebelPathsColumn')]
            .map((column) => [...column.querySelectorAll('.rebelPathsStepRow, .rebelPathsAroundCentre, .rebelPathsStartedHere, .rebelPathsLeftAfter')]
                .map((row) => row.textContent.replace(/\s+/g, ' ').trim())));
    }

    it('should show what visits viewed before and after a page that is picked', async function () {
        await page.click('.rebelPathsBy[data-by="urls"]');
        await page.select('.rebelPathsAroundPage', '/pricing');
        await page.waitForSelector('.rebelPathsAroundCentre');
        await page.waitForNetworkIdle();

        expect(await around()).to.deep.equal([
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

        expect((await around())[3]).to.have.members(['/pricing2', '/about1']);
        expect(await page.evaluate(() => document.querySelector('.rebelPathsAroundPage').value)).to.equal('/');
    });
});
