<?php
/**
 * AlloIA for PrestaShop
 *
 * @author    AlloIA Team
 * @copyright 2025 AlloIA
 * @license   MIT
 * @version   1.1.1
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/src/AlloiaApiClient.php';
require_once dirname(__FILE__) . '/src/ProductExporter.php';
require_once dirname(__FILE__) . '/src/AlloiaCore.php';
require_once dirname(__FILE__) . '/src/AlloiaUpdater.php';

class AlloiaPrestashop extends Module
{
    const CONFIG_API_KEY = 'ALLOIA_API_KEY';
    const CONFIG_AI_META_ENABLED = 'ALLOIA_AI_META_ENABLED';
    const CONFIG_LAST_SYNC_DATE = 'ALLOIA_LAST_SYNC_DATE';
    const CONFIG_LAST_SYNC_TOTAL = 'ALLOIA_LAST_SYNC_TOTAL';
    const CONFIG_LAST_SYNC_SENT = 'ALLOIA_LAST_SYNC_SENT';
    const CONFIG_LAST_SYNC_CREATED = 'ALLOIA_LAST_SYNC_CREATED';
    const CONFIG_LAST_SYNC_UPDATED = 'ALLOIA_LAST_SYNC_UPDATED';
    const CONFIG_LAST_SYNC_FAILED = 'ALLOIA_LAST_SYNC_FAILED';
    const CONFIG_LAST_SYNC_IGNORED = 'ALLOIA_LAST_SYNC_IGNORED';

    public $tabs = [
        [
            'name'               => 'AlloIA',
            'class_name'         => 'AdminAlloia',
            'visible'            => true,
            'parent_class_name'  => 'AdminCatalog',
            'icon'               => 'psychology',
        ],
    ];

    public function __construct()
    {
        $this->name = 'alloiaprestashop';
        $this->tab = 'administration';
        $this->version = '1.1.1';
        $this->author = 'AlloIA Team';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => '9.99.99',
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('AlloIA - AI commerce');
        $this->description = $this->l('Sync products to AlloIA knowledge graph, AI sitemap and product meta for AI crawlers.');
    }

    /**
     * @return bool
     */
    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        Configuration::updateValue(self::CONFIG_API_KEY, '');
        Configuration::updateValue(self::CONFIG_AI_META_ENABLED, true);

        $this->registerHook('displayHeader');
        $this->registerHook('displayBackOfficeHeader');
        $this->registerHook('actionObjectProductAddAfter');
        $this->registerHook('actionObjectProductUpdateAfter');
        $this->registerHook('actionUpdateQuantity');
        $this->registerHook('actionAdminMetaBeforeWriteRobotsFile');
        $this->registerHook('displayFooter');

        $this->installTab();

        return true;
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        Configuration::deleteByName(self::CONFIG_API_KEY);
        Configuration::deleteByName(self::CONFIG_AI_META_ENABLED);
        $this->uninstallTab();
        return parent::uninstall();
    }

    /**
     * Install back office tab (left menu entry under Catalogue).
     * The $this->tabs property handles this automatically on PS 1.7.7+;
     * this method is a fallback for older versions.
     */
    private function installTab()
    {
        if (Tab::getIdFromClassName('AdminAlloia')) {
            return true;
        }
        $tab = new Tab();
        $tab->class_name = 'AdminAlloia';
        $tab->module = $this->name;
        $tab->active = 1;
        $tab->icon = 'psychology';
        $idParent = (int) Tab::getIdFromClassName('AdminCatalog');
        $tab->id_parent = $idParent > 0 ? $idParent : 0;
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'AlloIA';
        }
        return $tab->add();
    }

    /**
     * Remove back office tab on uninstall.
     */
    private function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminAlloia');
        if ($idTab) {
            $tab = new Tab($idTab);
            $tab->delete();
        }
    }

    /**
     * Module configuration page
     */
    public function getContent()
    {
        if (Tools::getValue('alloia_ajax')) {
            $this->dispatchAjax();
            return '';
        }

        $output = '';

        // Check for available plugin update (cached 24 h)
        $updater = new AlloiaUpdater();
        if ($updater->isUpdateAvailable($this->version)) {
            $latestVersion = $updater->getLatestVersion();
            $releaseUrl    = $updater->getReleaseUrl() ?: 'https://github.com/PrescientMindAI/alloia-for-prestashop/releases/latest';
            $output .= $this->displayInformation(
                sprintf(
                    $this->l('A new version of AlloIA is available: %s. %s'),
                    '<strong>' . htmlspecialchars($latestVersion) . '</strong>',
                    '<a href="' . htmlspecialchars($releaseUrl) . '" target="_blank" rel="noopener">'
                    . $this->l('Download update') . '</a>'
                )
            );
        }

        // Warn if back office is not served over HTTPS (form would submit over unsecured connection)
        if (!$this->isSecureConnection()) {
            $output .= $this->displayWarning(
                $this->l('This page is not loaded over HTTPS. Saving may trigger a browser security warning. Enable SSL in PrestaShop: Shop Parameters > Traffic & SEO (or General), then set the shop URL to https:// and enable SSL.')
            );
        }
        if (Tools::isSubmit('submitAlloiaConfig')) {
            $apiKey = (string) Tools::getValue('ALLOIA_API_KEY');
            Configuration::updateValue(self::CONFIG_API_KEY, $apiKey);
            Configuration::updateValue(self::CONFIG_AI_META_ENABLED, (bool) Tools::getValue('ALLOIA_AI_META_ENABLED'));
            $output .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        return $output . $this->renderForm();
    }

    /**
     * Check if the current request is over HTTPS (avoids "unsecured connection" warning on Save).
     *
     * @return bool
     */
    protected function isSecureConnection()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }
        if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        return false;
    }

    /**
     * Build config form (API key, sync button, AI meta toggle)
     */
    protected function renderForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitAlloiaConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => [
                'ALLOIA_API_KEY' => Configuration::get(self::CONFIG_API_KEY),
                'ALLOIA_AI_META_ENABLED' => (bool) Configuration::get(self::CONFIG_AI_META_ENABLED),
            ],
        ];

        $form = [
            [
                'form' => [
                    'legend' => [
                        'title' => $this->l('AlloIA API'),
                        'icon' => 'icon-key',
                    ],
                    'input' => [
                        [
                            'type' => 'text',
                            'label' => $this->l('API Key'),
                            'name' => 'ALLOIA_API_KEY',
                            'desc' => $this->l('Enter your AlloIA API key (prefix ak_). Get it at alloia.ai'),
                            'autocomplete' => false,
                        ],
                        [
                            'type' => 'switch',
                            'label' => $this->l('AI meta on product pages'),
                            'name' => 'ALLOIA_AI_META_ENABLED',
                            'desc' => $this->l('Add link alternate and JSON-LD sameAs for AI crawlers.'),
                            'values' => [
                                ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')],
                                ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')],
                            ],
                        ],
                    ],
                    'submit' => [
                        'title' => $this->l('Save'),
                        'class' => 'btn btn-default pull-right',
                    ],
                ],
            ],
        ];

        return $helper->generateForm($form) . $this->renderSyncSection();
    }

    /**
     * Sync button + status (AJAX target)
     */
    protected function renderSyncSection()
    {
        $baseUrl = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);
        $validateUrl = $baseUrl . '&alloia_ajax=1&action=validateApiKey';
        $syncUrl = $baseUrl . '&alloia_ajax=1&action=syncAllProducts';

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $allProducts = Product::getProducts($idLang, 0, 10000, 'id_product', 'ASC', false, false);
        $totalProducts = is_array($allProducts) ? count($allProducts) : 0;

        $lastSyncDate = Configuration::get(self::CONFIG_LAST_SYNC_DATE);
        $lastSyncDateFormatted = $lastSyncDate ? date('Y-m-d H:i', strtotime($lastSyncDate)) : '';
        $lastSyncTotal = (int) Configuration::get(self::CONFIG_LAST_SYNC_TOTAL);
        $lastSyncSent = (int) Configuration::get(self::CONFIG_LAST_SYNC_SENT);
        $lastSyncCreated = (int) Configuration::get(self::CONFIG_LAST_SYNC_CREATED);
        $lastSyncUpdated = (int) Configuration::get(self::CONFIG_LAST_SYNC_UPDATED);
        $lastSyncFailed = (int) Configuration::get(self::CONFIG_LAST_SYNC_FAILED);
        $lastSyncIgnored = (int) Configuration::get(self::CONFIG_LAST_SYNC_IGNORED);

        $this->context->smarty->assign([
            'alloia_validate_url' => $validateUrl,
            'alloia_sync_url' => $syncUrl,
            'alloia_token' => Tools::getAdminTokenLite('AdminModules'),
            'alloia_total_products' => $totalProducts,
            'alloia_last_sync_date' => $lastSyncDate,
            'alloia_last_sync_date_formatted' => $lastSyncDateFormatted,
            'alloia_last_sync_total' => $lastSyncTotal,
            'alloia_last_sync_sent' => $lastSyncSent,
            'alloia_last_sync_created' => $lastSyncCreated,
            'alloia_last_sync_updated' => $lastSyncUpdated,
            'alloia_last_sync_failed' => $lastSyncFailed,
            'alloia_last_sync_ignored' => $lastSyncIgnored,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/config_sync.tpl');
    }

    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') !== $this->name) {
            return '';
        }
        $this->context->controller->addJS($this->_path . 'views/js/admin.js');
        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        return '';
    }

    public function hookDisplayHeader(array $params)
    {
        $core = new AlloiaCore($this);
        return $core->hookDisplayHeader($params);
    }

    public function hookActionAdminMetaBeforeWriteRobotsFile(array $params)
    {
        $core = new AlloiaCore($this);
        $core->hookActionAdminMetaBeforeWriteRobotsFile($params);
    }

    public function hookActionObjectProductAddAfter(array $params)
    {
        if (!isset($params['object']) || !($params['object'] instanceof Product)) {
            return;
        }
        $this->scheduleProductSync((int) $params['object']->id);
    }

    public function hookActionObjectProductUpdateAfter(array $params)
    {
        if (!isset($params['object']) || !($params['object'] instanceof Product)) {
            return;
        }
        $this->scheduleProductSync((int) $params['object']->id);
    }

    public function hookActionUpdateQuantity(array $params)
    {
        if (isset($params['id_product'])) {
            $this->scheduleProductSync((int) $params['id_product']);
        }
    }

    /**
     * Schedule single product sync (avoid blocking; optional delay)
     */
    private function scheduleProductSync($idProduct)
    {
        $apiKey = Configuration::get(self::CONFIG_API_KEY);
        if (empty($apiKey)) {
            return;
        }
        $exporter = new ProductExporter($this);
        $exporter->syncProductsByIds([$idProduct]);
    }

    /**
     * Dispatch AJAX actions (validate API key, sync all products)
     */
    private function dispatchAjax()
    {
        header('Content-Type: application/json; charset=utf-8');
        $action = Tools::getValue('action');
        if ($action === 'validateApiKey') {
            $apiKey = trim(Tools::getValue('api_key', Configuration::get(self::CONFIG_API_KEY)));
            if (empty($apiKey)) {
                die(json_encode(['success' => false, 'error' => $this->l('API key is empty.', 'alloiaprestashop')]));
            }
            $client = new AlloiaApiClient($apiKey);
            try {
                $result = $client->validateApiKey();
                die(json_encode($result));
            } catch (Exception $e) {
                die(json_encode(['success' => false, 'error' => $e->getMessage()]));
            }
        }
        if ($action === 'syncAllProducts') {
            $apiKey = Configuration::get(self::CONFIG_API_KEY);
            if (empty($apiKey)) {
                die(json_encode(['success' => false, 'error' => $this->l('Please set and save your API key first.', 'alloiaprestashop')]));
            }
            try {
                $exporter = new ProductExporter($this);
                $result = $exporter->syncAll();
                if (!empty($result['success']) && isset($result['total_products'])) {
                    Configuration::updateValue(self::CONFIG_LAST_SYNC_DATE, date('c'));
                    Configuration::updateValue(self::CONFIG_LAST_SYNC_TOTAL, (int) $result['total_products']);
                    Configuration::updateValue(self::CONFIG_LAST_SYNC_SENT, (int) ($result['products_sent'] ?? 0));
                    Configuration::updateValue(self::CONFIG_LAST_SYNC_CREATED, (int) ($result['products_created'] ?? 0));
                    Configuration::updateValue(self::CONFIG_LAST_SYNC_UPDATED, (int) ($result['products_updated'] ?? 0));
                    Configuration::updateValue(self::CONFIG_LAST_SYNC_FAILED, (int) ($result['products_failed'] ?? 0));
                    Configuration::updateValue(self::CONFIG_LAST_SYNC_IGNORED, (int) ($result['products_ignored'] ?? 0));
                }
                die(json_encode($result));
            } catch (Throwable $e) {
                die(json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                ]));
            }
        }
        die(json_encode(['success' => false, 'error' => 'Unknown action']));
    }

    /**
     * Base URL for AlloIA API
     */
    public static function getApiBaseUrl()
    {
        return 'https://www.alloia.io/api/v1';
    }
}
