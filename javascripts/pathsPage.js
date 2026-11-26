/*!
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

// The pages of the paths: switches between addresses and titles, draws the flow and the step
// explorer, and loads what is around a page and before a goal.
(function () {
    function el(tag, className, text) {
        var element = document.createElement(tag);
        element.className = className;
        if (text !== undefined) {
            element.textContent = text;
        }
        return element;
    }

    function number(value) {
        return value.toLocaleString();
    }

    var SVG = 'http://www.w3.org/2000/svg';
    var FLOW_WIDTH = 1000;
    var FLOW_HEIGHT = 300;
    var FLOW_TOP = 26;
    var FLOW_GAP = 8;
    var FLOW_NAME = 14;
    var FLOW_NODE = 12;
    var FLOW_LABEL = 170;
    var FLOW_LABEL_LENGTH = 26;
    // The pages of a step that get a bar of their own. The rest of the step is one bar.
    var FLOW_PAGES = 6;

    function svg(tag, attributes) {
        var element = document.createElementNS(SVG, tag);
        Object.keys(attributes).forEach(function (name) {
            element.setAttribute(name, attributes[name]);
        });
        return element;
    }

    // The pages of each step, whatever came before them, and the visits that went from one to the next.
    function flowOf(steps, maxSteps) {
        var columns = [];
        var links = {};

        (function walk(level, depth, parent) {
            level.forEach(function (step) {
                var key = step.l === null ? '' : 'p' + step.l;
                columns[depth] = columns[depth] || {};
                var node = columns[depth][key] = columns[depth][key] || { l: step.l, v: 0, x: 0, depth: depth, key: key };
                node.v += step.v;
                node.x += step.x;
                if (parent) {
                    var id = parent.key + '>' + depth + '>' + key;
                    links[id] = links[id] || { s: parent, t: node, v: 0 };
                    links[id].v += step.v;
                }
                if (depth + 1 < maxSteps) {
                    walk(step.c, depth + 1, node);
                }
            });
        })(steps, 0, null);

        columns = columns.map(function (column) {
            var nodes = Object.keys(column).map(function (key) { return column[key]; });
            var others = column[''] || { l: null, v: 0, x: 0, key: '' };
            nodes = nodes.filter(function (node) { return node !== others; }).sort(function (a, b) { return b.v - a.v; });
            nodes.slice(FLOW_PAGES).forEach(function (node) {
                others.v += node.v;
                others.x += node.x;
                node.as = others;
            });
            nodes = nodes.slice(0, FLOW_PAGES);
            if (others.v > 0) {
                nodes.push(others);
            }
            return nodes;
        });

        var merged = {};
        Object.keys(links).forEach(function (id) {
            var source = links[id].s.as || links[id].s;
            var target = links[id].t.as || links[id].t;
            var key = source.depth + source.key + '>' + target.key;
            merged[key] = merged[key] || { s: source, t: target, v: 0 };
            merged[key].v += links[id].v;
        });

        return { columns: columns, links: Object.keys(merged).map(function (key) { return merged[key]; }) };
    }

    function drawFlow(root) {
        var state = root.rebelPaths;
        var texts = state.texts;
        var box = root.querySelector('.rebelPathsFlow');
        var flow = flowOf(state.steps[state.by], state.maxSteps);
        box.textContent = '';

        if (!flow.columns.length) {
            box.appendChild(el('p', 'rebelPathsHint', texts.noData));
            return;
        }

        var total = function (nodes) {
            return nodes.reduce(function (sum, node) { return sum + node.v; }, 0);
        };
        var scale = FLOW_HEIGHT / Math.max.apply(null, flow.columns.map(total));
        var height = FLOW_TOP + Math.max.apply(null, flow.columns.map(function (nodes) {
            return nodes.reduce(function (sum, node) { return sum + Math.max(node.v * scale, FLOW_NAME) + FLOW_GAP; }, 0);
        }));
        var chart = svg('svg', { viewBox: '0 0 ' + FLOW_WIDTH + ' ' + height, role: 'img', 'aria-label': texts.flow });
        var linkLayer = chart.appendChild(svg('g', {}));
        var nodeLayer = chart.appendChild(svg('g', {}));
        var apart = flow.columns.length > 1 ? (FLOW_WIDTH - FLOW_LABEL - FLOW_NODE) / (flow.columns.length - 1) : 0;

        flow.columns.forEach(function (nodes, index) {
            var y = FLOW_TOP;
            var title = svg('text', { x: index * apart, y: 12, 'class': 'rebelPathsFlowStep' });
            title.textContent = texts.step.replace('%s', index + 1);
            nodeLayer.appendChild(title);
            nodes.forEach(function (node) {
                node.px = index * apart;
                node.py = y;
                node.h = Math.max(1, node.v * scale);
                node.out = 0;
                node.into = 0;
                // A page with few visits still gets the room of its name.
                y += Math.max(node.h, FLOW_NAME) + FLOW_GAP;
            });
        });

        flow.links.sort(function (a, b) { return a.t.py - b.t.py || a.s.py - b.s.py; }).forEach(function (link) {
            var h = Math.max(1, link.v * scale);
            var y1 = link.s.py + link.s.out + h / 2;
            var y2 = link.t.py + link.t.into + h / 2;
            var x1 = link.s.px + FLOW_NODE;
            var middle = (x1 + link.t.px) / 2;
            link.s.out += h;
            link.t.into += h;
            link.el = linkLayer.appendChild(svg('path', {
                'class': 'rebelPathsFlowLink',
                d: 'M' + x1 + ',' + y1 + ' C' + middle + ',' + y1 + ' ' + middle + ',' + y2 + ' ' + link.t.px + ',' + y2,
                'stroke-width': h,
            }));
        });

        function focus(node) {
            flow.links.forEach(function (link) {
                link.el.setAttribute('class', 'rebelPathsFlowLink' + (link.s === node || link.t === node ? ' active' : ''));
            });
        }

        flow.columns.forEach(function (nodes, index) {
            nodes.forEach(function (node) {
                var name = node.l === null ? texts.others : node.l;
                var left = Math.round(100 * node.x / node.v) + '%';
                var went = node.h * (node.v - node.x) / node.v;
                var group = nodeLayer.appendChild(svg('g', { 'class': 'rebelPathsFlowNode', tabindex: 0, 'data-step': index + 1 }));
                var numbersText = (node.v === 1 ? texts.flowNodeOne : texts.flowNode.replace('%1$s', number(node.v))).replace('%2$s', left);
                var tip = group.appendChild(svg('title', {}));
                tip.textContent = name + ': ' + numbersText;
                // So that a click anywhere on the page and its name picks it, not the band behind.
                group.appendChild(svg('rect', { 'class': 'rebelPathsFlowHit', x: node.px, y: node.py, width: FLOW_NODE + FLOW_LABEL, height: Math.max(node.h, 28) }));
                group.appendChild(svg('rect', { 'class': 'rebelPathsFlowOn', x: node.px, y: node.py, width: FLOW_NODE, height: went }));
                group.appendChild(svg('rect', { 'class': 'rebelPathsFlowLeft', x: node.px, y: node.py + went, width: FLOW_NODE, height: node.h - went }));
                var label = group.appendChild(svg('text', { x: node.px + FLOW_NODE + 7, y: node.py + 11 }));
                label.textContent = name.length > FLOW_LABEL_LENGTH ? name.substring(0, FLOW_LABEL_LENGTH - 1) + '…' : name;
                if (node.h >= 28) {
                    var numbers = group.appendChild(svg('text', { x: node.px + FLOW_NODE + 7, y: node.py + 25, 'class': 'rebelPathsFlowNumbers' }));
                    numbers.textContent = numbersText;
                }
                group.addEventListener('click', function () { focus(node); });
                group.addEventListener('focus', function () { focus(node); });
            });
        });

        box.appendChild(chart);
    }

    // The pages of the paths with their visits over all steps, the most visited first.
    function pagesOf(steps, searchLabel) {
        var visits = {};
        (function walk(level) {
            level.forEach(function (step) {
                if (step.l !== null && step.l !== searchLabel) {
                    visits['p' + step.l] = (visits['p' + step.l] || 0) + step.v;
                }
                walk(step.c);
            });
        })(steps);

        return Object.keys(visits).sort(function (a, b) { return visits[b] - visits[a]; }).slice(0, 100)
            .map(function (key) { return key.substring(1); });
    }

    function fillPages(root) {
        var state = root.rebelPaths;
        var select = root.querySelector('.rebelPathsAroundPage');
        select.textContent = '';
        pagesOf(state.steps[state.by], state.texts.search).forEach(function (label) {
            var option = el('option', '', label);
            option.value = label;
            select.appendChild(option);
        });
        // The page another page or report asked for is looked around first.
        var asked = hashParameters()[PAGE_PARAMETER];
        if (asked && !state.askedShown) {
            state.askedShown = true;
            selectPage(root, String(asked));
        }
        loadAround(root, select.value);
    }

    function loadAround(root, label) {
        var state = root.rebelPaths;
        var box = root.querySelector('.rebelPathsAround');
        box.textContent = '';
        if (!label) {
            box.appendChild(el('p', 'rebelPathsHint', state.texts.noData));
            return;
        }
        box.appendChild(el('p', 'rebelPathsHint', state.texts.loading));

        state.around = label;
        var by = state.by;
        var ajax = new window.ajaxHelper();
        ajax.addParams({ module: 'API', method: 'RebelPaths.getPagesAroundPage', format: 'json', page: label, byTitle: by === 'titles' ? 1 : 0 }, 'get');
        ajax.setFormat('json');
        ajax.setCallback(function (around) {
            // An answer that comes after another page was picked is not shown.
            if (state.around === label && state.by === by && around && around.steps) {
                drawAround(root, around);
            }
        });
        ajax.send();
    }

    // A column of pages with their visits and a bar for their share of the total. Picking a page calls onPick.
    function pageColumn(box, title, pages, total, texts, onPick) {
        var column = el('div', 'rebelPathsColumn');
        column.appendChild(el('div', 'rebelPathsColumnTitle', title));
        box.appendChild(column);

        (pages || []).forEach(function (other) {
            var item = el('div', 'rebelPathsStep');
            var row = el('span', 'rebelPathsStepRow');
            row.appendChild(el('span', 'rebelPathsStepLabel', other.label === '' ? texts.others : other.label));
            row.appendChild(el('span', 'rebelPathsStepVisits', number(other.nb_visits)));
            item.appendChild(row);
            var bar = el('span', 'rebelPathsStepBar rebelPathsAroundBar');
            var on = el('span', 'rebelPathsStepBarOn');
            on.style.width = (100 * other.nb_visits / total) + '%';
            bar.appendChild(on);
            item.appendChild(bar);
            if (onPick && other.label !== '' && other.label !== texts.search) {
                item.className += ' rebelPathsStepPick';
                item.setAttribute('role', 'button');
                item.setAttribute('tabindex', '0');
                var pick = function (event) {
                    if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') {
                        return;
                    }
                    event.preventDefault();
                    onPick(other.label);
                };
                item.addEventListener('click', pick);
                item.addEventListener('keydown', pick);
            }
            column.appendChild(item);
        });

        return column;
    }

    function centreColumn(box, title, label, visits, caption) {
        var column = el('div', 'rebelPathsColumn');
        column.appendChild(el('div', 'rebelPathsColumnTitle', title));
        var page = el('div', 'rebelPathsStep rebelPathsAroundCentre');
        page.appendChild(el('span', 'rebelPathsStepLabel', label));
        page.appendChild(el('span', 'rebelPathsAroundVisits', number(visits)));
        page.appendChild(el('span', 'rebelPathsStepLeft', caption));
        column.appendChild(page);
        box.appendChild(column);
    }

    var REMEMBERED = 'rebelPathsBy';
    var PAGE_PARAMETER = 'rebelPathsPage';

    function hashParameters() {
        var url = window.CoreHome && window.CoreHome.MatomoUrl;
        return url ? url.hashParsed.value : {};
    }

    // Opens the page that looks around a page, for the page with this label or address. With
    // "urls" or "titles" that is what the page is taken as, otherwise as what was chosen last.
    window.rebelPathsLookAround = function (page, by) {
        if (by) {
            try {
                window.sessionStorage.setItem(REMEMBERED, by);
            } catch (e) {
                // No storage in this browser: the page is taken as an address.
            }
        }
        var parameters = {};
        var current = hashParameters();
        Object.keys(current).forEach(function (name) {
            parameters[name] = current[name];
        });
        parameters.category = 'RebelPaths_Paths';
        parameters.subcategory = 'RebelPaths_AroundAPage';
        parameters[PAGE_PARAMETER] = page;
        window.CoreHome.MatomoUrl.updateHash(parameters);
    };

    function selectPage(root, label) {
        var select = root.querySelector('.rebelPathsAroundPage');
        if (![].some.call(select.options, function (option) { return option.value === label; })) {
            var option = el('option', '', label);
            option.value = label;
            select.appendChild(option);
        }
        select.value = label;
    }

    function lookAround(root, label) {
        selectPage(root, label);
        loadAround(root, label);
    }

    function drawAround(root, around) {
        var texts = root.rebelPaths.texts;
        var box = root.querySelector('.rebelPathsAround');
        var pick = function (label) { lookAround(root, label); };
        box.textContent = '';
        // A page that was asked for by its address comes back with its label.
        selectPage(root, around.label);
        root.rebelPaths.around = around.label;

        pageColumn(box, texts.around['-2'], around.steps['-2'], around.nb_visits, texts, pick);
        var before = pageColumn(box, texts.around['-1'], around.steps['-1'], around.nb_visits, texts, pick);
        if (around.nb_entries > 0) {
            before.appendChild(el('div', 'rebelPathsStartedHere', texts.startedHere.replace('%s', number(around.nb_entries))));
        }
        centreColumn(box, texts.thePage, around.label, around.nb_visits, texts.visits);
        var after = pageColumn(box, texts.around['1'], around.steps['1'], around.nb_visits, texts, pick);
        if (around.nb_exits > 0) {
            after.appendChild(el('div', 'rebelPathsLeftAfter', texts.endedHere.replace('%s', number(around.nb_exits))));
        }
        pageColumn(box, texts.around['2'], around.steps['2'], around.nb_visits, texts, pick);
    }

    function loadGoal(root) {
        var state = root.rebelPaths;
        var select = root.querySelector('.rebelPathsGoal');
        if (!select) {
            return;
        }
        var box = root.querySelector('.rebelPathsToGoal');
        var picked = select.value.split(':');
        var isFunnel = picked[0] === 'funnel';
        var idGoal = select.value;
        var by = state.by;
        box.textContent = '';
        root.querySelector('.rebelPathsOnTheWay').textContent = '';
        box.appendChild(el('p', 'rebelPathsHint', state.texts.loading));

        state.goal = idGoal;
        var ajax = new window.ajaxHelper();
        var parameters = { module: 'API', method: isFunnel ? 'RebelPaths.getPagesBeforeFunnel' : 'RebelPaths.getPagesBeforeGoal', format: 'json', byTitle: by === 'titles' ? 1 : 0 };
        parameters[isFunnel ? 'idFunnel' : 'idGoal'] = picked[1];
        ajax.addParams(parameters, 'get');
        ajax.setFormat('json');
        ajax.setCallback(function (before) {
            if (state.goal === idGoal && state.by === by && before && before.steps) {
                drawGoal(root, before, select.options[select.selectedIndex].textContent, isFunnel);
            }
        });
        ajax.send();
    }

    function drawGoal(root, before, goalName, isFunnel) {
        var texts = root.rebelPaths.texts;
        var box = root.querySelector('.rebelPathsToGoal');
        var pick = function (label) { window.rebelPathsLookAround(label); };
        box.textContent = '';
        if (!before.nb_visits) {
            box.appendChild(el('p', 'rebelPathsHint', texts.noData));
            return;
        }

        pageColumn(box, texts.threeBefore, before.steps['-3'], before.nb_visits, texts, pick);
        pageColumn(box, texts.around['-2'], before.steps['-2'], before.nb_visits, texts, pick);
        var last = pageColumn(box, texts.around['-1'], before.steps['-1'], before.nb_visits, texts, pick);
        if (before.nb_entries > 0) {
            last.appendChild(el('div', 'rebelPathsStartedHere', texts.startedHere.replace('%s', number(before.nb_entries))));
        }
        pageColumn(box, isFunnel ? texts.completedOn : texts.convertedOn, before.steps['0'], before.nb_visits, texts, pick);
        centreColumn(box, isFunnel ? texts.funnel : texts.goal, goalName, before.nb_visits, isFunnel ? texts.completed : texts.converted);

        var way = root.querySelector('.rebelPathsOnTheWay');
        before.pages.forEach(function (page) {
            var row = el('div', 'rebelPathsWay');
            row.appendChild(el('div', 'rebelPathsWayLabel', page.label));
            var bar = el('div', 'rebelPathsWayBar');
            [['rebelPathsWayConverted', page.nb_converted], ['rebelPathsWayLeft', page.nb_exits]].forEach(function (part) {
                var segment = el('span', part[0]);
                segment.style.width = (100 * part[1] / page.nb_visits) + '%';
                bar.appendChild(segment);
            });
            row.appendChild(bar);
            row.appendChild(el('div', 'rebelPathsWayNumbers', texts.onTheWay
                .replace('%1$s', number(page.nb_visits))
                .replace('%2$s', Math.round(100 * page.nb_converted / page.nb_visits) + '%')
                .replace('%3$s', Math.round(100 * page.nb_exits / page.nb_visits) + '%')));
            way.appendChild(row);
        });
    }

    function leftAfter(step, texts) {
        return el('div', 'rebelPathsLeftAfter', texts.leftAfter.replace('%1$s', number(step.x)).replace('%2$s', step.l));
    }

    function draw(root) {
        var state = root.rebelPaths;
        var texts = state.texts;
        var explorer = root.querySelector('.rebelPathsExplorer');
        var level = state.steps[state.by];
        var parent = null;
        var awaitsPick = false;
        explorer.textContent = '';

        for (var i = 0; i < state.maxSteps; i++) {
            var column = el('div', 'rebelPathsColumn');
            column.appendChild(el('div', 'rebelPathsColumnTitle', texts.step.replace('%s', i + 1)));
            explorer.appendChild(column);

            if (!level || !level.length) {
                if (parent) {
                    column.appendChild(leftAfter(parent, texts));
                } else if (i === 0) {
                    column.appendChild(el('p', 'rebelPathsHint', texts.noData));
                } else if (awaitsPick) {
                    column.appendChild(el('p', 'rebelPathsHint', texts.pick));
                    awaitsPick = false;
                }
                level = null;
                parent = null;
                continue;
            }

            var next = null;
            var nextParent = null;
            level.forEach(function (step, index) {
                var isPicked = state.picked[i] === index;
                var item = el('div', 'rebelPathsStep' + (isPicked ? ' active' : ''));
                var row = el('span', 'rebelPathsStepRow');
                row.appendChild(el('span', 'rebelPathsStepLabel', step.l === null ? texts.others : step.l));
                row.appendChild(el('span', 'rebelPathsStepVisits', number(step.v)));
                item.appendChild(row);

                if (step.l !== null) {
                    var bar = el('span', 'rebelPathsStepBar');
                    var on = el('span', 'rebelPathsStepBarOn');
                    on.style.width = (100 * (step.v - step.x) / step.v) + '%';
                    bar.appendChild(on);
                    item.appendChild(bar);
                    item.appendChild(el('span', 'rebelPathsStepLeft', texts.leftHere.replace('%s', Math.round(100 * step.x / step.v) + '%')));
                    // Not a button element: its content is laid out as a column, which a button does not do in every browser.
                    item.className += ' rebelPathsStepPick';
                    item.setAttribute('role', 'button');
                    item.setAttribute('tabindex', '0');
                    item.setAttribute('aria-pressed', isPicked ? 'true' : 'false');
                    var pick = (function (depth, picked) {
                        return function (event) {
                            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') {
                                return;
                            }
                            event.preventDefault();
                            state.picked = state.picked.slice(0, depth).concat([picked]);
                            draw(root);
                        };
                    })(i, index);
                    item.addEventListener('click', pick);
                    item.addEventListener('keydown', pick);
                }
                column.appendChild(item);

                if (isPicked) {
                    next = step.c;
                    nextParent = step;
                }
            });

            if (parent && parent.x > 0) {
                column.appendChild(leftAfter(parent, texts));
            }
            awaitsPick = state.picked[i] === undefined;
            level = awaitsPick ? null : next;
            parent = nextParent;
        }
    }

    function start(root) {
        root.rebelPaths = {
            steps: JSON.parse(root.getAttribute('data-steps')),
            texts: JSON.parse(root.getAttribute('data-texts')),
            maxSteps: parseInt(root.getAttribute('data-max-steps'), 10),
            by: 'urls',
            picked: [0],
        };
        function showBy(by) {
            root.rebelPaths.by = by;
            root.querySelectorAll('.rebelPathsBy').forEach(function (button) {
                button.classList.toggle('active', button.getAttribute('data-by') === by);
            });
            root.querySelectorAll('.rebelPathsTop').forEach(function (top) {
                top.hidden = top.getAttribute('data-by') !== by;
            });
        }
        // The choice between addresses and titles is kept from one page to the next.
        try {
            if (window.sessionStorage.getItem(REMEMBERED) === 'titles') {
                showBy('titles');
            }
        } catch (e) {
            // No storage in this browser.
        }

        // A page has one of the views, so each is drawn where its box is there.
        function drawAll() {
            if (root.querySelector('.rebelPathsExplorer')) {
                draw(root);
            }
            if (root.querySelector('.rebelPathsFlow')) {
                drawFlow(root);
            }
            if (root.querySelector('.rebelPathsAroundPage')) {
                fillPages(root);
            }
            loadGoal(root);
        }

        root.querySelectorAll('.rebelPathsBy').forEach(function (button) {
            button.addEventListener('click', function () {
                var by = button.getAttribute('data-by');
                showBy(by);
                root.rebelPaths.picked = [0];
                try {
                    window.sessionStorage.setItem(REMEMBERED, by);
                } catch (e) {
                    // No storage in this browser.
                }
                drawAll();
            });
        });
        if (root.querySelector('.rebelPathsAroundPage')) {
            root.querySelector('.rebelPathsAroundPage').addEventListener('change', function (event) {
                loadAround(root, event.target.value);
            });
        }
        if (root.querySelector('.rebelPathsGoal')) {
            root.querySelector('.rebelPathsGoal').addEventListener('change', function () {
                loadGoal(root);
            });
        }

        drawAll();
    }

    // Matomo puts the widget in the page after it has loaded, and again when the date changes.
    function startAll() {
        document.querySelectorAll('.rebelPaths:not([data-started])').forEach(function (root) {
            root.setAttribute('data-started', '1');
            start(root);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        startAll();
        new MutationObserver(startAll).observe(document.body, { childList: true, subtree: true });
    });
})();
