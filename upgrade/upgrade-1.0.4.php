<?php
/**
 * Upgrade script for AlloIA PrestaShop module v1.0.4
 * - Restores $tabs declaration (reverts regression from v1.0.3)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_4($module)
{
    // Re-create the tab if it was removed by v1.0.3
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
