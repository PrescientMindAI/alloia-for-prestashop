<?php
/**
 * AlloIA core: AI meta tags on product pages
 *
 * @author    AlloIA Team
 * @copyright 2025 AlloIA
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AlloiaCore
{
    /** @var AlloiaPrestashop */
    private $module;

    /** @var Context */
    private $context;

    public function __construct(AlloiaPrestashop $module)
    {
        $this->module = $module;
        $this->context = Context::getContext();
    }

    /**
     * Hook displayHeader: inject sitemap link on ALL pages + product AI meta on product pages.
     * Also emits AI-Insights analytics events for detected bot user-agents (AI-INSIGHTS-003).
     */
    public function hookDisplayHeader(array $params)
    {
        // AI-INSIGHTS-003: emit site_visit / checkout_click for bot user-agents (fire-and-forget)
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        if ($ua !== '' && $this->isAiBot($ua)) {
            $controller = Tools::getValue('controller');
            if (in_array($controller, ['cart', 'order', 'orderopc'], true)) {
                $this->emitBotEvent('checkout_click', Tools::getHttpHost(true) . $_SERVER['REQUEST_URI']);
            } else {
                // Determine product SKU when on a product page
                $productSku = '';
                if ($controller === 'product') {
                    $idProduct = (int) Tools::getValue('id_product');
                    if ($idProduct) {
                        $product = new Product($idProduct, false, (int) $this->context->language->id);
                        if (Validate::isLoadedObject($product)) {
                            $productSku = $product->reference ?: '';
                        }
                    }
                }
                $this->emitBotEvent('site_visit', Tools::getHttpHost(true) . $_SERVER['REQUEST_URI'], $productSku);
            }
        }

        if (!(bool) Configuration::get(AlloiaPrestashop::CONFIG_AI_META_ENABLED)) {
            return '';
        }

        $domain = $this->getShopDomain();
        $out = '';

        // --- MCP Discovery link: inject on every page (Story 3.3) ---
        $out .= '<link rel="mcp" href="/module/alloiaprestashop/mcp" type="application/json">' . "\n";

        // --- Sitemap link: inject on every page ---
        $origin = AlloiaPrestashop::getBaseOrigin();
        $sitemapUrl = $origin . '/sitemap.xml?domain=' . rawurlencode($domain);
        $out .= "\n" . '<link rel="sitemap" type="application/xml" href="' . htmlspecialchars($sitemapUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";

        // --- Product-specific tags: only on product pages ---
        $controller = $this->context->controller;
        if (!isset($controller->php_self) || $controller->php_self !== 'product') {
            return $out;
        }

        $idProduct = (int) Tools::getValue('id_product');
        if (!$idProduct) {
            return $out;
        }

        $idLang = (int) $this->context->language->id;
        $product = new Product($idProduct, false, $idLang);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return $out;
        }

        $linkRewrite = $product->link_rewrite;
        if (is_array($linkRewrite)) {
            $linkRewrite = isset($linkRewrite[$idLang]) ? $linkRewrite[$idLang] : reset($linkRewrite);
        }
        $slug    = $linkRewrite ?: ('product-' . $idProduct);
        $langIso = $this->context->language->iso_code ?: 'en';
        $graphUrl = $origin . '/product/' . rawurlencode($langIso) . '/' . rawurlencode($slug) . '?domain=' . rawurlencode($domain);

        $productUrl = $this->context->link->getProductLink($product);
        $shopName   = Configuration::get('PS_SHOP_NAME') ?: 'Shop';
        $name        = $product->name;
        $description = strip_tags($product->description_short ?: $product->description);
        $sku         = $product->reference ?: ('presta-' . $idProduct);
        $basePrice   = (float) $product->getPrice(true, null, 2);
        $currency    = $this->context->currency->iso_code ?: 'EUR';
        $priceUntil  = date('Y-m-d', strtotime('+1 year'));

        // --- Image ---
        $imageUrls = [];
        $allImages = Image::getImages($idLang, $idProduct);
        foreach ($allImages as $img) {
            $imgUrl = $this->context->link->getImageLink($linkRewrite, $img['id_image'], 'large_default');
            if (strpos($imgUrl, 'http') !== 0) {
                $imgUrl = (Configuration::get('PS_SSL_ENABLED') ? 'https' : 'http') . '://' . $imgUrl;
            }
            $imageUrls[] = $imgUrl;
        }

        // --- Brand ---
        $brandName = '';
        if (!empty($product->id_manufacturer)) {
            $brandName = (string) Manufacturer::getNameById((int) $product->id_manufacturer);
        }

        // --- GTIN / MPN ---
        $gtin = '';
        if (!empty($product->ean13))  { $gtin = $product->ean13; }
        elseif (!empty($product->isbn))  { $gtin = $product->isbn; }
        elseif (!empty($product->upc))   { $gtin = $product->upc; }
        $mpn = !empty($product->mpn) ? $product->mpn : '';

        // --- Offers: one per combination, or single offer ---
        $combinations = $product->getAttributeCombinations($idLang);
        if (!empty($combinations)) {
            $seen   = [];
            $offers = [];
            foreach ($combinations as $combo) {
                $idAttr = (int) $combo['id_product_attribute'];
                if (isset($seen[$idAttr])) {
                    continue;
                }
                $seen[$idAttr] = true;
                $comboPrice = (float) $product->getPrice(true, $idAttr, 2);
                $comboStock = (int) StockAvailable::getQuantityAvailableByProduct($idProduct, $idAttr) > 0;
                $offer = [
                    '@type'           => 'Offer',
                    'url'             => $productUrl,
                    'priceCurrency'   => $currency,
                    'price'           => $comboPrice,
                    'availability'    => $comboStock
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                    'priceValidUntil' => $priceUntil,
                    'seller'          => ['@type' => 'Organization', 'name' => $shopName],
                ];
                if (!empty($combo['reference'])) { $offer['sku']    = $combo['reference']; }
                if (!empty($combo['ean13']))      { $offer['gtin13'] = $combo['ean13']; }
                if (!empty($combo['mpn']))        { $offer['mpn']    = $combo['mpn']; }
                $offers[] = $offer;
            }
            // variesBy: collect unique attribute group names
            $variesBy = [];
            foreach ($combinations as $combo) {
                if (!empty($combo['group_name']) && !in_array($combo['group_name'], $variesBy, true)) {
                    $variesBy[] = $combo['group_name'];
                }
            }
        } else {
            $inStock = (int) StockAvailable::getQuantityAvailableByProduct($idProduct) > 0;
            $offers  = [
                '@type'           => 'Offer',
                'url'             => $productUrl,
                'priceCurrency'   => $currency,
                'price'           => $basePrice,
                'availability'    => $inStock
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'priceValidUntil' => $priceUntil,
                'seller'          => ['@type' => 'Organization', 'name' => $shopName],
            ];
            $variesBy = [];
        }

        $rating = $this->getProductRating($idProduct);

        $productData = ['@context' => 'https://schema.org', '@type' => 'Product'];
        $productData['name']        = $name;
        $productData['url']         = $productUrl;
        if (!empty($imageUrls)) {
            $productData['image'] = count($imageUrls) === 1 ? $imageUrls[0] : $imageUrls;
        }
        if ($description !== '') {
            $productData['description'] = $description;
        }
        $productData['sku'] = $sku;
        if ($gtin !== '')    { $productData['gtin']  = $gtin; }
        if ($mpn !== '')     { $productData['mpn']   = $mpn; }
        if ($brandName !== '') {
            $productData['brand'] = ['@type' => 'Brand', 'name' => $brandName];
        }
        if (!empty($variesBy)) {
            $productData['variesBy'] = $variesBy;
        }
        $productData['offers'] = $offers;
        if ($rating !== null) {
            $productData['aggregateRating'] = $rating;
        }

        $out .= "<!-- AlloIA AI-Optimized Product Data -->\n";
        $out .= '<link rel="alternate" type="application/json" href="' . htmlspecialchars($graphUrl, ENT_QUOTES, 'UTF-8') . '" title="AI-Optimized Product Data">' . "\n";
        $out .= '<meta name="ai-content-source" content="' . htmlspecialchars($graphUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        $out .= '<script type="application/ld+json">' . "\n";
        $out .= json_encode($productData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $out .= "\n</script>\n";
        $out .= "<!-- /AlloIA AI-Optimized Data -->\n";

        return $out;
    }

    /**
     * Inject human AI-referral detection snippet in page footer.
     * AI-INSIGHTS-006
     */
    public function hookDisplayFooter(array $params): string
    {
        $apiKey = Configuration::get(AlloiaPrestashop::CONFIG_API_KEY);
        if (empty($apiKey)) {
            return '';
        }

        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        if ($this->isAiBot($userAgent)) {
            return '';
        }

        $productSku = '';
        $context = Context::getContext();
        if (isset($context->controller) && $context->controller instanceof ProductController) {
            $product = $context->controller->getProduct();
            if ($product instanceof Product) {
                $productSku = addslashes($product->reference);
            }
        }

        $apiKeyJs = addslashes($apiKey);
        $shopDomainJs = addslashes(strtolower(Context::getContext()->shop->domain));
        $analyticsUrl = addslashes(AlloiaPrestashop::getApiBaseUrl() . '/analytics/human-visit');

        return <<<HTML
<script>
(function() {
  var KNOWN_AI_REFERRERS = [
    'chat.openai.com','chatgpt.com','perplexity.ai','claude.ai',
    'copilot.microsoft.com','gemini.google.com','you.com','phind.com','poe.com','bing.com'
  ];
  var params  = new URLSearchParams(window.location.search);
  var ref     = document.referrer || '';
  var utmDetected = params.get('utm_source') === 'alloia_ai';
  var refHost = '';
  try { refHost = ref ? new URL(ref).hostname : ''; } catch(e) {}
  var knownRef = KNOWN_AI_REFERRERS.some(function(d){ return refHost === d || refHost.endsWith('.'+d); });

  if (!utmDetected && !knownRef) return;

  fetch('{$analyticsUrl}', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer {$apiKeyJs}', 'Content-Type': 'application/json', 'X-Alloia-Domain': '{$shopDomainJs}' },
    body: JSON.stringify({
      referrer_domain: refHost,
      utm_detected: utmDetected,
      page_url: window.location.href,
      product_sku: '{$productSku}'
    }),
    keepalive: true
  }).catch(function(){});
})();
</script>
HTML;
    }

    /**
     * Hook actionAdminMetaBeforeWriteRobotsFile: append AlloIA sitemap to robots.txt.
     * Fires when admin saves SEO & URLs → Generate robots.txt.
     *
     * @param array $params  ['content' => &array of lines]
     */
    public function hookActionAdminMetaBeforeWriteRobotsFile(array $params)
    {
        $domain = $this->getShopDomain();
        $origin = AlloiaPrestashop::getBaseOrigin();
        $sitemapUrl = $origin . '/sitemap.xml?domain=' . rawurlencode($domain);

        // $params['content'] is an array of lines passed by reference
        if (isset($params['content']) && is_array($params['content'])) {
            // Remove any pre-existing AlloIA sitemap entry to avoid duplicates
            $params['content'] = array_filter($params['content'], function ($line) {
                return strpos($line, 'alloia.io/sitemap.xml') === false;
            });
            $params['content'][] = 'Sitemap: ' . $sitemapUrl;
            $params['content'][] = '# AI-MCP-Endpoint: /module/alloiaprestashop/mcp';
        }
    }

    /**
     * Detect known AI/search-engine bot user-agents.
     * AI-INSIGHTS-003
     */
    private function isAiBot(string $ua): bool
    {
        $patterns = [
            'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'Claude-Web', 'ClaudeBot',
            'Anthropic', 'PerplexityBot', 'YouBot', 'cohere-ai', 'Amazonbot',
            'Googlebot', 'Bingbot', 'bingbot', 'Applebot', 'DuckDuckBot',
            'facebookexternalhit', 'Twitterbot', 'LinkedInBot', 'Slurp',
            'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot',
        ];
        foreach ($patterns as $pattern) {
            if (stripos($ua, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Emit a non-blocking analytics event to AlloIA API.
     * Uses cURL with 1s timeout — fire-and-forget, no retry, no error propagation.
     * AI-INSIGHTS-003
     *
     * @param string $eventType   'site_visit' | 'checkout_click'
     * @param string $urlVisited  Full URL of the visited page
     * @param string $productSku  Product reference (empty string if not applicable)
     */
    private function emitBotEvent(string $eventType, string $urlVisited, string $productSku = ''): void
    {
        $apiKey = Configuration::get(AlloiaPrestashop::CONFIG_API_KEY);
        if (empty($apiKey)) {
            return;
        }

        $payload = json_encode([
            'event_type'     => $eventType,
            'bot_user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
            'url_visited'    => $urlVisited,
            'product_sku'    => $productSku,
            'metadata'       => [],
        ]);

        $ch = curl_init(AlloiaPrestashop::getApiBaseUrl() . '/analytics/site-visit');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
                'X-Alloia-Domain: ' . strtolower(Context::getContext()->shop->domain),
            ],
            CURLOPT_TIMEOUT        => 1,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Returns a real aggregateRating array for the given product, or null if no validated
     * reviews exist or the productcomments module is not installed/enabled.
     *
     * @param int $idProduct
     * @return array|null
     */
    private function getProductRating(int $idProduct): ?array
    {
        if (!Module::isInstalled('productcomments') || !Module::isEnabled('productcomments')) {
            return null;
        }
        $row = Db::getInstance()->getRow('
            SELECT AVG(grade) AS avg_grade, COUNT(*) AS total
            FROM `' . _DB_PREFIX_ . 'product_comment`
            WHERE id_product = ' . (int)$idProduct . '
              AND validate = 1
        ');
        $total = (int)($row['total'] ?? 0);
        if ($total === 0) {
            return null;
        }
        return [
            '@type'       => 'AggregateRating',
            'ratingValue' => number_format((float)$row['avg_grade'], 1),
            'reviewCount' => $total,
            'bestRating'  => '5',
            'worstRating' => '1',
        ];
    }

    /**
     * Returns the shop's public domain (e.g. "myshop.com"), used as the ?domain= param.
     */
    private function getShopDomain()
    {
        // Prefer HTTP_HOST from the actual request (most reliable)
        if (!empty($_SERVER['HTTP_HOST'])) {
            return preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST']);
        }

        // Fallback: parse the configured shop URL
        $url = Configuration::get('PS_SHOP_URL') ?: (Tools::getShopDomain(true) . __PS_BASE_URI__);
        $parsed = parse_url($url);
        return isset($parsed['host']) ? $parsed['host'] : '';
    }
}
