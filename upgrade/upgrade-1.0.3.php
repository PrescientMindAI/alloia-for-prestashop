<?php
/**
 * Upgrade script for AlloIA PrestaShop module v1.0.3
 * - Removes $tabs declaration to fix OOM during install on low-memory servers
 * - Tab is still installed/removed manually via installTab/uninstallTab
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_3($module)
{
    return true;
}
