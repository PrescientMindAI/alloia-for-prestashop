<?php
/**
 * Upgrade script for AlloIA PrestaShop module v1.0.2
 * - Hotfix: AdminAlloiaController now redirects before loading Symfony DI container
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_2($module)
{
    return true;
}
