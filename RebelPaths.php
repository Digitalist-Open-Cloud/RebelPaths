<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelPaths;

use Piwik\Category\Subcategory;
use Piwik\Plugin;
use Piwik\Plugins\RebelPaths\Categories\PathsCategory;

class RebelPaths extends Plugin
{
    public function registerEvents()
    {
        return [
            'AssetManager.getStylesheetFiles' => 'getStylesheetFiles',
            'AssetManager.getJavaScriptFiles' => 'getJavaScriptFiles',
            'Category.addSubcategories' => 'addSubcategories',
            'Metrics.getDefaultMetricTranslations' => 'addMetricTranslations',
            'Metrics.getDefaultMetricDocumentationTranslations' => 'addMetricDocumentationTranslations',
        ];
    }

    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = 'plugins/RebelPaths/stylesheets/menuIcon.less';
        $stylesheets[] = 'plugins/RebelPaths/stylesheets/paths.less';
    }

    public function getJavaScriptFiles(&$files)
    {
        $files[] = 'plugins/RebelPaths/javascripts/pathsPage.js';
    }

    public function addSubcategories(&$subcategories)
    {
        foreach (array_keys(PathsPage::PAGES) as $order => $id) {
            $subcategory = new Subcategory();
            $subcategory->setCategoryId(PathsCategory::ID);
            $subcategory->setId($id);
            $subcategory->setName($id);
            $subcategory->setOrder($order + 1);
            $subcategories[] = $subcategory;
        }
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
