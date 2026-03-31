<?php
/**
 * Upgrade script for AlloIA PrestaShop module v1.0.1
 * - Adds left menu tab under Catalogue
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    // Install the back office menu tab if not already present
    if (!Tab::getIdFromClassName('AdminAlloia')) {
        $tab = new Tab();
        $tab->class_name = 'AdminAlloia';
        $tab->module = $module->name;
        $tab->active = 1;
        $tab->icon = 'psychology';
        $idParent = (int) Tab::getIdFromClassName('AdminCatalog');
        $tab->id_parent = $idParent > 0 ? $idParent : 0;
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'AlloIA';
        }
        return $tab->add();
    }
    return true;
}
