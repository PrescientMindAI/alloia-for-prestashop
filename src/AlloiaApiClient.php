<?php
/**
 * AlloIA API client for PrestaShop
 *
 * @author    AlloIA Team
 * @copyright 2025 AlloIA
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AlloiaApiClient
{
    private $baseUrl;
    private $apiKey;

    public function __construct($apiKey = null)
    {
        $this->apiKey = $apiKey ?: Configuration::get('ALLOIA_API_KEY');
        $this->baseUrl = rtrim(AlloiaPrestashop::getApiBaseUrl(), '/');
    }

    /**
     * Validate API key - GET /api/v1/clients/validate
     *
     * @return array Decoded JSON response
     * @throws Exception On HTTP or API error
     */
    public function validateApiKey()
    {
        return $this->request('/clients/validate', 'GET');
    }

    /**
     * Validate domain for sync (validates key and returns domain info)
     *
     * @param string|null $domain Optional domain (default: current shop URL host)
     * @return array ['valid' => bool, 'domain' => string, 'client_id' => string, 'error' => string]
     */
    public function validateDomainForSync($domain = null)
    {
        try {
            $result = $this->validateApiKey();
            $success = !empty($result['success']) && !empty($result['valid']);
            if (!$success) {
                return [
                    'valid' => false,
                    'error' => isset($result['error']['message']) ? $result['error']['message'] : $this->l('Invalid API key'),
                    'domain' => $domain,
                ];
            }
            if ($domain === null || $domain === '') {
                $domain = $this->getShopDomain();
            }
            $domain = trim($domain);
            if ($domain === '') {
                return ['valid' => false, 'error' => $this->l('Could not determine shop domain'), 'domain' => null];
            }
            return [
                'valid' => true,
                'domain' => $domain,
                'client_id' => isset($result['client']['id']) ? $result['client']['id'] : '',
            ];
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'domain' => $domain ?? '',
            ];
        }
    }

    /**
     * Bulk ingest products - POST /api/v1/ingest with X-Platform: prestashop and body.domain
     *
     * @param array  $products Array of product payloads (plugin format)
     * @param string $domain   Shop domain for validation
     * @return array Decoded JSON response
     * @throws Exception On HTTP or API error
     */
    public function bulkIngest($products, $domain)
    {
        $data = [
            'products' => $products,
        ];
        $headers = [
            'X-Platform' => 'prestashop',
            'X-Alloia-Domain' => strtolower(Context::getContext()->shop->domain),
        ];
        return $this->request('/ingest', 'POST', $data, $headers);
    }

    /**
     * Make HTTP request
     *
     * @param string $endpoint   e.g. /clients/validate
     * @param string $method     GET or POST
     * @param array  $data       Body for POST
     * @param array  $extraHeaders Extra headers (e.g. X-Platform)
     * @return array Decoded JSON
     * @throws Exception
     */
    private function request($endpoint, $method = 'GET', $data = null, $extraHeaders = [])
    {
        $url = $this->baseUrl . $endpoint;
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: AlloIA-PrestaShop-Plugin/1.0',
        ];
        foreach ($extraHeaders as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (strtoupper($method) === 'POST' && $data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new Exception('HTTP request failed: ' . $err);
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response: ' . substr($body, 0, 200));
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'HTTP ' . $httpCode;
            throw new Exception($msg);
        }

        return $decoded;
    }

    private function getShopDomain()
    {
        return strtolower(Context::getContext()->shop->domain);
    }

    private function l($msg)
    {
        return Translate::getModuleTranslation('alloiaprestashop', $msg, 'alloiaapiclient');
    }
}
