<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths;

use Piwik\Plugin;

class RebelPaths extends Plugin
{
    public function registerEvents()
    {
        return [
            'AssetManager.getStylesheetFiles' => 'getStylesheetFiles',
            'AssetManager.getJavaScriptFiles' => 'getJavaScriptFiles',
            'Metrics.getDefaultMetricTranslations' => 'addMetricTranslations',
            'Metrics.getDefaultMetricDocumentationTranslations' => 'addMetricDocumentationTranslations',
        ];
    }

    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = 'plugins/RebelPaths/stylesheets/paths.less';
    }

    public function getJavaScriptFiles(&$files)
    {
        $files[] = 'plugins/RebelPaths/javascripts/pathsOverview.js';
    }

    public function addMetricTranslations(&$translations)
    {
        $translations['nb_path_exits'] = 'RebelPaths_ColumnExits';
        $translations['path_exit_rate'] = 'RebelPaths_ColumnExitRate';
    }

    public function addMetricDocumentationTranslations(&$translations)
    {
        $translations['nb_path_exits'] = 'RebelPaths_ColumnExitsDocumentation';
        $translations['path_exit_rate'] = 'RebelPaths_ColumnExitRateDocumentation';
    }
}
