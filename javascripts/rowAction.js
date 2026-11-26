/*!
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

// Opens the page "Around a page" for a row of a report of page addresses.
(function () {
    var REPORTS = ['getPageUrls', 'getEntryPageUrls', 'getExitPageUrls'];

    function RowAction(dataTable) {
        this.dataTable = dataTable;
    }

    RowAction.prototype = new window.DataTable_RowAction();

    RowAction.prototype.trigger = function (tr) {
        window.rebelPathsLookAround(tr.find('> td:first > a').attr('href'));
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
            return dataTableParams.module === 'Actions' && REPORTS.indexOf(dataTableParams.action) !== -1;
        },

        // A folder of pages has no address of its own.
        isAvailableOnRow: function (dataTableParams, tr) {
            return !tr.hasClass('subDataTable') && !tr.hasClass('totalsRow') && !!tr.find('> td:first > a').attr('href');
        },
    });
})();
