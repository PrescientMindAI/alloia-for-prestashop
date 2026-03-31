<?php
/**
 * AlloIA MCP Front Controller for PrestaShop
 * Exposes MCP tool definitions, resources, proxy call, and well-known capabilities.
 *
 * URL pattern: /module/alloiaprestashop/mcp?action={tools|resources|call|wellknown}
 *
 * @author    AlloIA Team
 * @copyright 2025 AlloIA
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AlloiaMcpModuleFrontController extends ModuleFrontController
{
    private const TOOL_MAP = [
        'search_products'  => ['method' => 'POST', 'path' => '/api/llm/search'],
        'get_product'      => ['method' => 'GET',  'path' => '/api/llm/products/{id}'],
        'list_categories'  => ['method' => 'GET',  'path' => '/api/llm/categories'],
        'get_manufacturer' => ['method' => 'GET',  'path' => '/api/llm/manufacturers/{id}'],
    ];

    private const CACHE_KEY = 'alloia_mcp_capabilities';

    public function __construct()
    {
        parent::__construct();
        $this->ajax = true;
    }

    public function initContent()
    {
        parent::initContent();

        $action = Tools::getValue('action', 'tools');

        switch ($action) {
            case 'tools':
                $this->handleToolsAction();
                break;
            case 'resources':
                $this->handleResourcesAction();
                break;
            case 'call':
                $this->handleCallAction();
                break;
            case 'wellknown':
                $this->handleWellknownAction();
                break;
            default:
                $this->errorResponse('Unknown action: ' . $action);
        }
    }

    // -------------------------------------------------------------------------
    // Story 3.1 — Tools and Resources list
    // -------------------------------------------------------------------------

    private function handleToolsAction(): void
    {
        if (empty($this->getApiKey())) {
            $this->errorResponse('Module not configured');
        }
        $this->jsonResponse(['tools' => $this->getToolsDefinition()]);
    }

    private function handleResourcesAction(): void
    {
        if (empty($this->getApiKey())) {
            $this->errorResponse('Module not configured');
        }
        $this->jsonResponse([
            'resources' => [
                ['id' => 'catalog',    'description' => 'Full product catalog with AI-enriched data'],
                ['id' => 'categories', 'description' => 'Product category hierarchy'],
            ],
        ]);
    }

    private function getToolsDefinition(): array
    {
        return [
            [
                'name'        => 'search_products',
                'description' => 'Search the Alloia product knowledge graph',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query'    => ['type' => 'string',  'description' => 'Search query'],
                        'limit'    => ['type' => 'integer', 'description' => 'Max results (default 10)'],
                        'category' => ['type' => 'string',  'description' => 'Filter by category slug'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name'        => 'get_product',
                'description' => 'Retrieve full product details from Alloia graph',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'                   => ['type' => 'integer', 'description' => 'Product ID'],
                        'includeRelationships' => ['type' => 'boolean', 'description' => 'Include related products'],
                        'includeManufacturer'  => ['type' => 'boolean', 'description' => 'Include manufacturer data'],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name'        => 'list_categories',
                'description' => 'List product categories from Alloia graph',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'parentId'     => ['type' => 'integer', 'description' => 'Filter by parent category ID'],
                        'includeStats' => ['type' => 'boolean', 'description' => 'Include product count stats'],
                    ],
                ],
            ],
            [
                'name'        => 'get_manufacturer',
                'description' => 'Retrieve manufacturer details from Alloia graph',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Manufacturer ID'],
                    ],
                    'required' => ['id'],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Story 3.2 — Proxy tool call (action=call)
    // -------------------------------------------------------------------------

    private function handleCallAction(): void
    {
        if (empty($this->getApiKey())) {
            $this->errorResponse('Module not configured');
        }

        $rawBody   = file_get_contents('php://input');
        $body      = json_decode($rawBody ?: '', true) ?? [];
        $tool      = isset($body['tool']) ? (string) $body['tool'] : '';
        $arguments = isset($body['arguments']) ? $body['arguments'] : null;

        if (!array_key_exists($tool, self::TOOL_MAP)) {
            $this->jsonResponse(['error' => 'Unknown tool: ' . $tool, 'code' => 'UNKNOWN_TOOL']);
        }
        if (!is_array($arguments)) {
            $this->jsonResponse(['error' => 'Arguments must be an object', 'code' => 'INVALID_ARGUMENTS']);
        }

        $this->proxyToolCall($tool, (array) $arguments);
    }

    private function proxyToolCall(string $tool, array $arguments): void
    {
        $baseUrl  = (string) Configuration::get('ALLOIA_API_URL', 'https://alloia.io');
        $mapEntry = self::TOOL_MAP[$tool];
        $path     = $mapEntry['path'];

        if (isset($arguments['id']) && strpos($path, '{id}') !== false) {
            $path = str_replace('{id}', (int) $arguments['id'], $path);
        }

        $url     = $baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->getApiKey(),
            'X-Original-Host: ' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''),
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if ($mapEntry['method'] === 'POST') {
            $curlOpts[CURLOPT_URL]        = $url;
            $curlOpts[CURLOPT_POST]       = true;
            $curlOpts[CURLOPT_POSTFIELDS] = json_encode($arguments);
        } else {
            $curlOpts[CURLOPT_URL] = $url . (empty($arguments) ? '' : '?' . http_build_query($arguments));
        }

        curl_setopt_array($ch, $curlOpts);

        $responseBody = curl_exec($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_errno($ch);
        curl_close($ch);

        if ($curlError) {
            $this->jsonResponse(['error' => 'Proxy request failed', 'code' => 'PROXY_ERROR']);
        }
        if ($httpCode === 404) {
            $this->jsonResponse(['error' => 'Store not registered on Alloia', 'code' => 'CLIENT_NOT_FOUND']);
        }

        header('Content-Type: application/json; charset=utf-8');
        die($responseBody);
    }

    // -------------------------------------------------------------------------
    // Story 3.3 — Well-known capabilities (action=wellknown)
    // -------------------------------------------------------------------------

    private function handleWellknownAction(): void
    {
        $cached = Cache::retrieve(self::CACHE_KEY);
        if ($cached === false || $cached === null) {
            $cached = $this->getMcpCapabilitiesJson();
            Cache::store(self::CACHE_KEY, $cached, 3600);
        }
        $this->jsonResponse($cached);
    }

    private function getMcpCapabilitiesJson(): array
    {
        return [
            'mcp_version'    => '1.0',
            'provider'       => 'alloia.ai',
            'endpoint'       => '/module/alloiaprestashop/mcp',
            'auth'           => 'none',
            'identification' => 'domain-based',
            'capabilities'   => [
                'tools'     => ['search_products', 'get_product', 'list_categories', 'get_manufacturer'],
                'resources' => ['catalog', 'categories'],
                'trust'     => 'planned',
                'acp'       => 'planned',
                'ucp'       => 'planned',
            ],
            'powered_by' => 'https://alloia.ai',
        ];
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private function getApiKey(): string
    {
        return (string) Configuration::get('ALLOIA_API_KEY', '');
    }

    private function jsonResponse(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode($data));
    }

    private function errorResponse(string $message): void
    {
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['error' => $message]));
    }
}
