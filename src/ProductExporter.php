<?php
/**
 * Export PrestaShop products to AlloIA API payload format
 *
 * @author    AlloIA Team
 * @copyright 2025 AlloIA
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ProductExporter
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
     * Shop base URL (with protocol) for absolute permalink/image URLs
     */
    private function getBaseUrl()
    {
        if (isset($this->context->link) && method_exists($this->context->link, 'getBaseLink')) {
            $base = $this->context->link->getBaseLink();
            if (is_string($base) && (strpos($base, 'http://') === 0 || strpos($base, 'https://') === 0)) {
                return rtrim($base, '/');
            }
        }
        $protocol = Configuration::get('PS_SSL_ENABLED') ? 'https' : 'http';
        $domain = Tools::getShopDomain(false);
        return $protocol . '://' . $domain . rtrim(__PS_BASE_URI__, '/');
    }

    /**
     * Ensure URL is absolute (prepend base if relative)
     */
    private function ensureAbsoluteUrl($url)
    {
        if (!is_string($url) || $url === '') {
            return $url;
        }
        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            return $url;
        }
        $base = $this->getBaseUrl();
        return $base . (strpos($url, '/') === 0 ? $url : '/' . $url);
    }

    /**
     * Sync all active products to AlloIA
     *
     * @return array ['success' => bool, 'message' => string, 'created' => int, 'updated' => int, 'failed' => int, 'error' => string]
     */
    public function syncAll()
    {
        $apiKey = Configuration::get(AlloiaPrestashop::CONFIG_API_KEY);
        if (empty($apiKey)) {
            return ['success' => false, 'error' => $this->module->l('API key not configured.', 'ProductExporter')];
        }

        $client = new AlloiaApiClient($apiKey);
        $domainValidation = $client->validateDomainForSync();
        if (empty($domainValidation['valid'])) {
            return [
                'success' => false,
                'error' => isset($domainValidation['error']) ? $domainValidation['error'] : $this->module->l('Domain validation failed.', 'ProductExporter'),
            ];
        }
        $domain = $domainValidation['domain'];

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $allProducts = Product::getProducts($idLang, 0, 10000, 'id_product', 'ASC', false, false);
        $totalProducts = is_array($allProducts) ? count($allProducts) : 0;

        $productIds = Product::getProducts($idLang, 0, 10000, 'id_product', 'ASC', false, true);
        if (empty($productIds)) {
            return [
                'success' => true,
                'message' => $this->module->l('No products to sync.', 'ProductExporter'),
                'products_processed' => 0,
                'products_created' => 0,
                'products_updated' => 0,
                'products_failed' => 0,
                'total_products' => $totalProducts,
                'products_sent' => 0,
                'products_ignored' => $totalProducts,
            ];
        }

        $ids = array_map(function ($p) {
            return (int) $p['id_product'];
        }, $productIds);
        $payloads = $this->buildPayloadsForIds($ids);
        $productsSent = is_array($payloads) ? count($payloads) : 0;
        $productsIgnored = $totalProducts - $productsSent;

        if (empty($payloads)) {
            return [
                'success' => true,
                'message' => $this->module->l('No product data to send.', 'ProductExporter'),
                'products_processed' => 0,
                'products_created' => 0,
                'products_updated' => 0,
                'products_failed' => 0,
                'total_products' => $totalProducts,
                'products_sent' => 0,
                'products_ignored' => $productsIgnored,
            ];
        }

        $batches = array_chunk($payloads, 50);
        $created = 0;
        $updated = 0;
        $failed = 0;
        $lastError = '';

        foreach ($batches as $batchIndex => $batch) {
            try {
                $response = $client->bulkIngest($batch, $domain);
                $data = isset($response['data']) ? $response['data'] : [];
                $created += isset($data['products_created']) ? (int) $data['products_created'] : 0;
                $updated += isset($data['products_updated']) ? (int) $data['products_updated'] : 0;
                $failed  += isset($data['products_failed'])  ? (int) $data['products_failed']  : 0;
            } catch (Exception $e) {
                $failed += count($batch);
                $lastError = $e->getMessage();
            }
            if ($batchIndex < count($batches) - 1) {
                sleep(1);
            }
        }

        $processed = $created + $updated + $failed;

        $result = [
            'success' => true,
            'message' => $this->module->l('Sync completed.', 'ProductExporter'),
            'products_processed' => $processed,
            'products_created' => $created,
            'products_updated' => $updated,
            'products_failed' => $failed,
            'total_products' => $totalProducts,
            'products_sent' => $productsSent,
            'products_ignored' => $productsIgnored,
        ];
        if ($lastError !== '') {
            $result['last_error'] = $lastError;
        }
        return $result;
    }

    /**
     * Sync specific products by ID
     *
     * @param int[] $idProducts
     */
    public function syncProductsByIds(array $idProducts)
    {
        if (empty($idProducts)) {
            return;
        }
        $apiKey = Configuration::get(AlloiaPrestashop::CONFIG_API_KEY);
        if (empty($apiKey)) {
            return;
        }
        $client = new AlloiaApiClient($apiKey);
        $domainValidation = $client->validateDomainForSync();
        if (empty($domainValidation['valid'])) {
            return;
        }
        $payloads = $this->buildPayloadsForIds($idProducts);
        if (empty($payloads)) {
            return;
        }
        try {
            $client->bulkIngest($payloads, $domainValidation['domain']);
        } catch (Exception $e) {
            // Log and ignore for background sync
        }
    }

    /**
     * Build API payloads for product IDs (format expected by transformPrestaShopProduct)
     *
     * @param int[] $idProducts
     * @return array[]
     */
    public function buildPayloadsForIds(array $idProducts)
    {
        $payloads = [];
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $currencyCode = $currency->iso_code ?: 'EUR';

        foreach ($idProducts as $idProduct) {
            $product = new Product((int) $idProduct, false, $idLang);
            if (!Validate::isLoadedObject($product) || !$product->active) {
                continue;
            }

            $payload = $this->buildProductPayload($product, $idLang, $currencyCode);
            if ($payload !== null) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * Build single product payload (compatible with API transformPrestaShopProduct)
     *
     * @param Product $product
     * @param int     $idLang
     * @param string  $currencyCode
     * @return array|null
     */
    private function buildProductPayload(Product $product, $idLang, $currencyCode)
    {
        $idProduct = (int) $product->id;
        $quantity = (int) StockAvailable::getQuantityAvailableByProduct($idProduct);
        $inStock = $quantity > 0;

        $name = is_array($product->name) ? (isset($product->name[$idLang]) ? $product->name[$idLang] : reset($product->name)) : $product->name;
        $descriptionRaw = $product->description;
        $shortDescriptionRaw = $product->description_short;
        if (is_array($descriptionRaw)) {
            $description = isset($descriptionRaw[$idLang]) ? $descriptionRaw[$idLang] : (string) reset($descriptionRaw);
        } else {
            $description = (string) $descriptionRaw;
        }
        if (is_array($shortDescriptionRaw)) {
            $shortDescription = isset($shortDescriptionRaw[$idLang]) ? $shortDescriptionRaw[$idLang] : (string) reset($shortDescriptionRaw);
        } else {
            $shortDescription = (string) $shortDescriptionRaw;
        }
        if ($description === '' && $shortDescription !== '') {
            $description = $shortDescription;
        }
        $reference = $product->reference;
        $sku = $reference ?: ('presta-' . $idProduct);
        $price = (float) $product->getPrice(true, null, 2);
        $ean13 = isset($product->ean13) ? trim((string) $product->ean13) : '';
        $upc = isset($product->upc) ? trim((string) $product->upc) : '';
        $isbn = isset($product->isbn) ? trim((string) $product->isbn) : '';
        $mpn = isset($product->mpn) ? trim((string) $product->mpn) : '';
        $conditionRaw = isset($product->condition) ? trim((string) $product->condition) : 'new';
        $condition = $conditionRaw === 'refurbished' ? 'reconditioned' : (in_array($conditionRaw, ['new', 'used', 'reconditioned'], true) ? $conditionRaw : 'new');
        $category = 'general';
        if ($product->id_category_default) {
            $cat = new Category((int) $product->id_category_default, $idLang);
            if (Validate::isLoadedObject($cat)) {
                $category = $cat->name;
            }
        }
        $manufacturer = '';
        if ($product->id_manufacturer) {
            $manu = new Manufacturer((int) $product->id_manufacturer);
            if (Validate::isLoadedObject($manu)) {
                $manufacturer = $manu->name;
            }
        }
        $linkRewrite = $product->link_rewrite;
        if (is_array($linkRewrite)) {
            $linkRewrite = isset($linkRewrite[$idLang]) ? $linkRewrite[$idLang] : reset($linkRewrite);
        }
        $permalink = $this->context->link->getProductLink($product);
        $permalink = $this->ensureAbsoluteUrl($permalink);
        try {
            $images = $this->getProductImageUrls($idProduct, $idLang);
        } catch (Throwable $e) {
            $images = [];
        }
        $images = array_map([$this, 'ensureAbsoluteUrl'], $images);
        try {
            $tags = $this->getProductTags($idProduct, $idLang);
        } catch (Throwable $e) {
            $tags = [];
        }
        $characteristics = $this->getProductCharacteristics($idProduct, $idLang);
        $associatedProducts = $this->getProductAccessories($idProduct, $idLang);

        $dimensionUnit = Configuration::get('PS_DIMENSION_UNIT') ?: 'cm';
        $weightUnit = Configuration::get('PS_WEIGHT_UNIT') ?: 'kg';
        $dimensions = $this->buildProductDimensions($product, $dimensionUnit, $weightUnit);

        $variants = [];
        $combinations = $product->getAttributeCombinations($idLang);
        $hasVariations = !empty($combinations);
        $combinationImages = [];
        if ($hasVariations && method_exists($product, 'getCombinationImages')) {
            $combinationImages = $product->getCombinationImages($idLang);
            if (!is_array($combinationImages)) {
                $combinationImages = [];
            }
        }
        if ($hasVariations) {
            // Group by id_product_attribute (one combination can have multiple rows for multiple attribute groups)
            $byAttr = [];
            foreach ($combinations as $combo) {
                $idProductAttribute = (int) $combo['id_product_attribute'];
                if (!isset($byAttr[$idProductAttribute])) {
                    $byAttr[$idProductAttribute] = ['reference' => isset($combo['reference']) ? $combo['reference'] : '', 'options' => []];
                }
                // PrestaShop: group_name = option name (e.g. "Type de papier"), attribute_name = option value (e.g. "Ligné")
                $attrName = isset($combo['group_name']) ? trim((string) $combo['group_name']) : (isset($combo['attribute_name']) ? trim((string) $combo['attribute_name']) : '');
                $attrValue = isset($combo['attribute_name']) ? trim((string) $combo['attribute_name']) : (isset($combo['value']) ? trim((string) $combo['value']) : '');
                if ($attrName !== '' || $attrValue !== '') {
                    $byAttr[$idProductAttribute]['options'][] = ['name' => $attrName, 'value' => $attrValue];
                }
            }
            foreach ($byAttr as $idProductAttribute => $data) {
                $qtyCombo = (int) StockAvailable::getQuantityAvailableByProduct($idProduct, $idProductAttribute);
                $options = $data['options'];
                $optionLabels = array_map(function ($o) {
                    return ($o['name'] !== '' ? $o['name'] . ' - ' : '') . $o['value'];
                }, $options);
                $variantPrice = (float) $product->getPrice(true, $idProductAttribute, 2);
                $variantIdentifiers = $this->getCombinationIdentifiers($idProductAttribute);
                $variantImages = $this->getCombinationImageUrls($idProduct, $idProductAttribute, $idLang, $linkRewrite, $combinationImages);
                $variants[] = array_merge([
                    'sku' => !empty($data['reference']) ? $data['reference'] : ($sku . '-' . $idProductAttribute),
                    'reference' => $data['reference'],
                    'price' => $variantPrice,
                    'regular_price' => $variantPrice,
                    'sale_price' => null,
                    'in_stock' => $qtyCombo > 0,
                    'inventory_quantity' => $qtyCombo,
                    'selectedOptions' => $options,
                    'option_label' => implode(', ', $optionLabels),
                    'images' => $variantImages,
                    'checkout_url' => $this->getAddToCartUrl($idProduct, $idProductAttribute),
                    'dimensions' => $this->buildVariantDimensions($product, $idProductAttribute, $dimensionUnit, $weightUnit),
                ], $variantIdentifiers);
            }
        }

        $priceRange = null;
        if ($hasVariations && !empty($variants)) {
            $prices = array_column($variants, 'price');
            $priceRange = [
                'min' => (float) min($prices),
                'max' => (float) max($prices),
                'currency' => $currencyCode,
            ];
        }
        $productType = $hasVariations ? 'variable' : 'simple';

        return [
            'prestashop_id' => $idProduct,
            'id_product' => $idProduct,
            'name' => $name,
            'description' => $description,
            'short_description' => strip_tags($shortDescription),
            'sku' => $sku,
            'reference' => $reference,
            'category' => $category,
            'price' => $price,
            'currency' => $currencyCode,
            'stock_status' => $inStock ? 'instock' : 'outofstock',
            'in_stock' => $inStock,
            'stock_quantity' => $quantity,
            'quantity' => $quantity,
            'manufacturer' => $manufacturer ?: 'Unknown',
            'images' => $images,
            'tags' => $tags,
            'permalink' => $permalink,
            'slug' => $linkRewrite,
            'link_rewrite' => $linkRewrite,
            'date_created' => $product->date_add,
            'date_modified' => $product->date_upd,
            'variants' => $variants,
            'has_variations' => $hasVariations,
            'variation_count' => count($variants),
            'characteristics' => $characteristics,
            'associated_products' => $associatedProducts,
            'gtin' => $ean13 ?: null,
            'ean' => $ean13 ?: null,
            'upc' => $upc ?: null,
            'isbn' => $isbn ?: null,
            'mpn' => $mpn ?: null,
            'condition' => $condition,
            'dimensions' => $dimensions,
            'product_type' => $productType,
            'price_range' => $priceRange,
        ];
    }

    /**
     * Product dimensions (WooCommerce-style: length, width, height, weight, unit).
     */
    private function buildProductDimensions(Product $product, $dimensionUnit, $weightUnit)
    {
        $w = isset($product->width) ? (float) $product->width : 0;
        $h = isset($product->height) ? (float) $product->height : 0;
        $d = isset($product->depth) ? (float) $product->depth : 0;
        $weight = isset($product->weight) ? (float) $product->weight : 0;
        if ($w <= 0 && $h <= 0 && $d <= 0 && $weight <= 0) {
            return null;
        }
        return [
            'length' => $d,
            'width' => $w,
            'height' => $h,
            'unit' => $dimensionUnit,
            'weight' => $weight > 0 ? $weight : null,
            'weight_unit' => $weight > 0 ? $weightUnit : null,
        ];
    }

    /**
     * Variant dimensions (combination weight/dimensions if set, else product).
     */
    private function buildVariantDimensions(Product $product, $idProductAttribute, $dimensionUnit, $weightUnit)
    {
        $base = $this->buildProductDimensions($product, $dimensionUnit, $weightUnit);
        try {
            $combo = new Combination((int) $idProductAttribute);
            if (Validate::isLoadedObject($combo) && isset($combo->weight) && (float) $combo->weight != 0) {
                if (!$base) {
                    $base = ['length' => null, 'width' => null, 'height' => null, 'unit' => $dimensionUnit, 'weight' => (float) $combo->weight, 'weight_unit' => $weightUnit];
                } else {
                    $base['weight'] = (float) $combo->weight;
                }
            }
        } catch (Throwable $e) {
            // keep base
        }
        return $base;
    }

    /**
     * Image URLs for a combination (per-variant images). Falls back to product cover if none.
     */
    private function getCombinationImageUrls($idProduct, $idProductAttribute, $idLang, $linkRewrite, array $combinationImages)
    {
        $urls = [];
        if (isset($combinationImages[$idProductAttribute]) && is_array($combinationImages[$idProductAttribute])) {
            $imageType = 'large_default';
            foreach ($combinationImages[$idProductAttribute] as $img) {
                $idImage = isset($img['id_image']) ? (int) $img['id_image'] : 0;
                if ($idImage && isset($this->context->link) && method_exists($this->context->link, 'getImageLink')) {
                    try {
                        $url = $this->context->link->getImageLink($linkRewrite, $idImage, $imageType);
                        if ($url) {
                            $urls[] = $this->ensureAbsoluteUrl($url);
                        }
                    } catch (Throwable $e) {
                        continue;
                    }
                }
            }
        }
        if (empty($urls)) {
            $cover = Product::getCover($idProduct);
            if ($cover) {
                $idImage = (int) $cover['id_image'];
                if (isset($this->context->link) && method_exists($this->context->link, 'getImageLink')) {
                    try {
                        $url = $this->context->link->getImageLink($linkRewrite, $idImage, 'large_default');
                        if ($url) {
                            $urls[] = $this->ensureAbsoluteUrl($url);
                        }
                    } catch (Throwable $e) {
                        // ignore
                    }
                }
            }
        }
        return $urls;
    }

    /**
     * Add-to-cart URL for product + combination (for direct checkout/variant link).
     */
    private function getAddToCartUrl($idProduct, $idProductAttribute)
    {
        if (!isset($this->context->link) || !method_exists($this->context->link, 'getPageLink')) {
            return '';
        }
        $cartUrl = $this->context->link->getPageLink('cart', true);
        $cartUrl = $this->ensureAbsoluteUrl($cartUrl);
        $sep = strpos($cartUrl, '?') !== false ? '&' : '?';
        $params = [
            'add' => 1,
            'id_product' => (int) $idProduct,
            'id_product_attribute' => (int) $idProductAttribute,
            'qty' => 1,
        ];
        return $cartUrl . $sep . http_build_query($params);
    }

    /**
     * EAN13, UPC, ISBN, MPN for a product combination (déclinaison).
     *
     * @param int $idProductAttribute
     * @return array{gtin?: string, ean?: string, upc?: string, isbn?: string, mpn?: string}
     */
    private function getCombinationIdentifiers($idProductAttribute)
    {
        $out = [];
        try {
            $combination = new Combination((int) $idProductAttribute);
            if (!Validate::isLoadedObject($combination)) {
                return $out;
            }
            $ean13 = isset($combination->ean13) ? trim((string) $combination->ean13) : '';
            $upc = isset($combination->upc) ? trim((string) $combination->upc) : '';
            $isbn = isset($combination->isbn) ? trim((string) $combination->isbn) : '';
            $mpn = isset($combination->mpn) ? trim((string) $combination->mpn) : '';
            if ($ean13 !== '') {
                $out['gtin'] = $ean13;
                $out['ean'] = $ean13;
            }
            if ($upc !== '') {
                $out['upc'] = $upc;
            }
            if ($isbn !== '') {
                $out['isbn'] = $isbn;
            }
            if ($mpn !== '') {
                $out['mpn'] = $mpn;
            }
        } catch (Throwable $e) {
            // Ignore: variant will have no combination identifiers
        }
        return $out;
    }

    /**
     * Caractéristiques (features) — e.g. Composition => Céramique
     * NOT attributes (attributs = variations: size, color). Features = feature_product + feature_lang + feature_value_lang.
     * Uses getFrontFeaturesStatic when available; fallback to direct DB query when
     * Feature is disabled (isFeatureActive = false) so we still export characteristics.
     * To verify in DB: SELECT pf.*, fl.name, fvl.value FROM ps_feature_product pf
     *   LEFT JOIN ps_feature_lang fl ON fl.id_feature=pf.id_feature AND fl.id_lang=1
     *   LEFT JOIN ps_feature_value_lang fvl ON fvl.id_feature_value=pf.id_feature_value AND fvl.id_lang=1
     *   WHERE pf.id_product=14;
     *
     * @param int $idProduct
     * @param int $idLang
     * @return array<array{name: string, value: string}>
     */
    private function getProductCharacteristics($idProduct, $idLang)
    {
        $out = [];
        try {
            if (method_exists('Product', 'getFrontFeaturesStatic')) {
                $rows = Product::getFrontFeaturesStatic($idLang, $idProduct);
                if (is_array($rows) && count($rows) > 0) {
                    foreach ($rows as $row) {
                        $name = isset($row['name']) ? trim((string) $row['name']) : '';
                        $value = isset($row['value']) ? trim((string) $row['value']) : '';
                        if ($name !== '' || $value !== '') {
                            $out[] = ['name' => $name, 'value' => $value];
                        }
                    }
                    return $out;
                }
            }
            // Fallback: query feature tables directly (Features = Caractéristiques, NOT attributes)
            // Tables: feature_product (link), feature_lang (feature name), feature_value_lang (value)
            $prefix = _DB_PREFIX_;
            $getId = function ($row, $key) {
                foreach ([$key, ucfirst($key), strtoupper($key)] as $k) {
                    if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) {
                        return trim((string) $row[$k]);
                    }
                }
                return '';
            };
            $idShop = (isset($this->context->shop) && $this->context->shop->id) ? (int) $this->context->shop->id : 0;
            $shopFilter = '';
            if ($idShop > 0 && method_exists('Shop', 'addSqlAssociation')) {
                // Multishop: only features associated to current shop (feature_shop)
                $shopFilter = ' AND EXISTS (SELECT 1 FROM ' . $prefix . 'feature_shop fs WHERE fs.id_feature = pf.id_feature AND fs.id_shop = ' . $idShop . ')';
            }
            $sql = 'SELECT fl.`name`, fvl.`value`
                 FROM ' . $prefix . 'feature_product pf
                 LEFT JOIN ' . $prefix . 'feature_lang fl ON (fl.id_feature = pf.id_feature AND fl.id_lang = ' . (int) $idLang . ')
                 LEFT JOIN ' . $prefix . 'feature_value_lang fvl ON (fvl.id_feature_value = pf.id_feature_value AND fvl.id_lang = ' . (int) $idLang . ')
                 WHERE pf.id_product = ' . (int) $idProduct . $shopFilter . '
                 ORDER BY pf.id_feature';
            $rows = Db::getInstance()->executeS($sql);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $name = $getId($row, 'name');
                    $value = $getId($row, 'value');
                    if ($name !== '' || $value !== '') {
                        $out[] = ['name' => $name, 'value' => $value];
                    }
                }
            }
            // If still empty and multishop was used, try without shop filter (feature_shop may be empty)
            if (count($out) === 0 && $idShop > 0 && $shopFilter !== '') {
                $rows2 = Db::getInstance()->executeS(
                    'SELECT fl.`name`, fvl.`value`
                     FROM ' . $prefix . 'feature_product pf
                     LEFT JOIN ' . $prefix . 'feature_lang fl ON (fl.id_feature = pf.id_feature AND fl.id_lang = ' . (int) $idLang . ')
                     LEFT JOIN ' . $prefix . 'feature_value_lang fvl ON (fvl.id_feature_value = pf.id_feature_value AND fvl.id_lang = ' . (int) $idLang . ')
                     WHERE pf.id_product = ' . (int) $idProduct . '
                     ORDER BY pf.id_feature'
                );
                if (is_array($rows2)) {
                    foreach ($rows2 as $row) {
                        $name = $getId($row, 'name');
                        $value = $getId($row, 'value');
                        if ($name !== '' || $value !== '') {
                            $out[] = ['name' => $name, 'value' => $value];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        return $out;
    }

    /**
     * Produits associés (accessories)
     *
     * @param int $idProduct
     * @param int $idLang
     * @return array<array{id_product: int, name: string, reference: string}>
     */
    private function getProductAccessories($idProduct, $idLang)
    {
        if (!method_exists('Product', 'getAccessoriesLight')) {
            return [];
        }
        try {
            $rows = Product::getAccessoriesLight($idLang, $idProduct);
            if (!is_array($rows)) {
                return [];
            }
            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id_product' => (int) $row['id_product'],
                    'name' => isset($row['name']) ? (string) $row['name'] : '',
                    'reference' => isset($row['reference']) ? (string) $row['reference'] : '',
                ];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function getProductImageUrls($idProduct, $idLang)
    {
        $images = [];
        $cover = Product::getCover($idProduct);
        $idCover = $cover ? (int) $cover['id_image'] : 0;
        $product = new Product((int) $idProduct, false, $idLang);
        $linkRewrite = $product->link_rewrite;
        if (is_array($linkRewrite)) {
            $linkRewrite = isset($linkRewrite[$idLang]) ? $linkRewrite[$idLang] : reset($linkRewrite);
        }
        $allImages = $product->getImages($idLang);
        $imageType = 'large_default';
        if (!isset($this->context->link) || !method_exists($this->context->link, 'getImageLink')) {
            return $images;
        }
        foreach ($allImages as $img) {
            $idImage = (int) $img['id_image'];
            try {
                $url = $this->context->link->getImageLink($linkRewrite, $idImage, $imageType);
                if ($url) {
                    $images[] = $url;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        return $images;
    }

    private function getProductTags($idProduct, $idLang)
    {
        $tags = Tag::getProductTags($idProduct);
        if (isset($tags[$idLang]) && is_array($tags[$idLang])) {
            return $tags[$idLang];
        }
        return [];
    }
}
