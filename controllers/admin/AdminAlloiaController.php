<?php
/**
 * AlloIA back office controller.
 * Kept minimal — does not call parent::__construct() to avoid
 * loading the Symfony DI container (OOM on servers with 128 MB limit).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminAlloiaController extends ModuleAdminController
{
    public function __construct()
    {
        // Redirect immediately, before anything heavy is loaded.
        $context = Context::getContext();
        if ($context && !empty($context->link)) {
            $url = $context->link->getAdminLink('AdminModules', true, [], [
                'configure'   => 'alloiaprestashop',
                'tab_module'  => 'administration',
                'module_name' => 'alloiaprestashop',
            ]);
            header('Location: ' . $url);
            exit;
        }

        // Only falls through if link is not ready (should not happen).
        $this->bootstrap = true;
        parent::__construct();
    }
}
