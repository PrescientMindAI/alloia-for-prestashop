<?php
/**
 * Upgrade script for AlloIA PrestaShop module v1.0.5
 * - Inject <link rel="sitemap"> on all pages with ?domain= param
 * - Inject Sitemap line in robots.txt via actionAdminMetaBeforeWriteRobotsFile hook
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_5($module)
{
    return $module->registerHook('actionAdminMetaBeforeWriteRobotsFile');
}
