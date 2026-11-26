/*!
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

// Opens the page "Around a page" for a row of a report of pages, by address or by title.
(function () {
    var URL_REPORTS = ['getPageUrls', 'getEntryPageUrls', 'getExitPageUrls'];
    var TITLE_REPORTS = ['getPageTitles', 'getEntryPageTitles', 'getExitPageTitles'];

    function RowAction(dataTable) {
        this.dataTable = dataTable;
    }

    RowAction.prototype = new window.DataTable_RowAction();

    RowAction.prototype.trigger = function (tr) {
        if (URL_REPORTS.indexOf(this.dataTable.param.action) !== -1) {
            window.rebelPathsLookAround(tr.find('> td:first > a').attr('href'), 'urls');
            return;
        }
        // A title can be split over the rows of a group, which Matomo puts together again.
        window.DataTable_RowAction.prototype.trigger.apply(this, arguments);
    };

    RowAction.prototype.performAction = function (label) {
        var parts = label.split(' > ').map(function (part) {
            return decodeURIComponent(part.replace('@', '')).trim();
        });
        window.rebelPathsLookAround(parts.join(window.piwik.config.action_title_category_delimiter), 'titles');
    };

    window.DataTable_RowActions_Registry.register({
        name: 'RebelPaths',
        // Matomo takes a name that starts with "icon-" as a class, and anything else as the address of an image.
        dataTableIcon: 'icon-rebel-paths',
        order: 25,
        dataTableIconTooltip: [window._pk_translate('RebelPaths_AroundAPage'), window._pk_translate('RebelPaths_RowActionTooltip')],

        createInstance: function (dataTable) {
            return new RowAction(dataTable);
        },

        isAvailableOnReport: function (dataTableParams) {
            return dataTableParams.module === 'Actions' && URL_REPORTS.concat(TITLE_REPORTS).indexOf(dataTableParams.action) !== -1;
        },

        // A group of pages is not a page, and a page without an address cannot be asked for by it.
        isAvailableOnRow: function (dataTableParams, tr) {
            return !tr.hasClass('subDataTable') && !tr.hasClass('totalsRow')
                && (TITLE_REPORTS.indexOf(dataTableParams.action) !== -1 || !!tr.find('> td:first > a').attr('href'));
        },
    });
})();
