<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para leer productos desde Meta Catalog
 * Los productos en Meta provienen de WooCommerce (sincronización automática)
 * Este servicio SOLO lee, no crea/edita/elimina
 */
class MetaCatalogService
{
    private string $baseUrl;
    private string $accessToken;
    private string $catalogId;
    private string $apiVersion;

    public function __construct()
    {
        $this->baseUrl = config('meta.api_base_url');
        $this->accessToken = config('meta.access_token');
        $this->catalogId = config('meta.catalog_id');
        $this->apiVersion = config('meta.api_version');
    }

    private function buildUrl(string $endpoint): string
    {
        return "{$this->baseUrl}/{$this->apiVersion}/{$endpoint}";
    }

    private function parsePrice($price): float
    {
        if (is_numeric($price)) {
            return (float) $price;
        }

        if (is_string($price)) {
            $cleaned = preg_replace('/[^0-9.]/', '', $price);
            return (float) $cleaned;
        }

        return 0.0;
    }

    private function normalizeProduct(array $metaProduct): array
    {
        return [
            'meta_product_id' => $metaProduct['id'],
            'retailer_id' => $metaProduct['retailer_id'] ?? null, // ID de WooCommerce
            'name' => $metaProduct['name'] ?? 'Sin nombre',
            'description' => $metaProduct['description'] ?? null,
            'price' => $this->parsePrice($metaProduct['price'] ?? 0),
            'image_url' => $metaProduct['image_url'] ?? null,
            'url' => $metaProduct['url'] ?? null,
            'availability' => $metaProduct['availability'] ?? 'in stock',
        ];
    }

    /**
     * Obtener productos con paginación
     */
    public function getProducts(int $limit = 100): array
    {
        try {
            $url = $this->buildUrl("{$this->catalogId}/products");

            $response = Http::get($url, [
                'access_token' => $this->accessToken,
                'fields' => 'id,retailer_id,name,description,price,availability,image_url,url',
                'limit' => $limit,
            ]);

            if ($response->failed()) {
                Log::error('Meta API Error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new \Exception('Error al obtener productos de Meta: ' . $response->body());
            }

            $data = $response->json();

            $normalizedProducts = array_map(
                fn($product) => $this->normalizeProduct($product),
                $data['data'] ?? []
            );

            return [
                'products' => $normalizedProducts,
                'paging' => $data['paging'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('Meta Catalog Service Error', [
                'method' => 'getProducts',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Obtener TODOS los productos (paginación automática)
     */
    public function getAllProducts(): array
    {
        $allProducts = [];
        $nextUrl = null;

        try {
            $result = $this->getProducts(100);
            $allProducts = array_merge($allProducts, $result['products']);
            $nextUrl = $result['paging']['next'] ?? null;

            while ($nextUrl) {
                $response = Http::get($nextUrl);

                if ($response->failed()) {
                    Log::warning('Failed to fetch next page', ['url' => $nextUrl]);
                    break;
                }

                $data = $response->json();

                $normalizedProducts = array_map(
                    fn($product) => $this->normalizeProduct($product),
                    $data['data'] ?? []
                );

                $allProducts = array_merge($allProducts, $normalizedProducts);
                $nextUrl = $data['paging']['next'] ?? null;
            }

            return $allProducts;

        } catch (\Exception $e) {
            Log::error('Error getting all products', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Obtener un producto específico
     */
    public function getProduct(string $productId): ?array
    {
        try {
            $url = $this->buildUrl($productId);

            $response = Http::get($url, [
                'access_token' => $this->accessToken,
                'fields' => 'id,retailer_id,name,description,price,availability,image_url,url',
            ]);

            if ($response->failed()) {
                Log::error('Meta API Error getting product', [
                    'product_id' => $productId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $this->normalizeProduct($response->json());

        } catch (\Exception $e) {
            Log::error('Error getting product from Meta', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Test de conexión
     */
    public function testConnection(): bool
    {
        try {
            $url = $this->buildUrl($this->catalogId);

            $response = Http::get($url, [
                'access_token' => $this->accessToken,
                'fields' => 'id,name',
            ]);

            return $response->successful();

        } catch (\Exception $e) {
            Log::error('Meta connection test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Obtener información del catálogo
     */
    public function getCatalogInfo(): ?array
    {
        try {
            $url = $this->buildUrl($this->catalogId);

            $response = Http::get($url, [
                'access_token' => $this->accessToken,
                'fields' => 'id,name,product_count,vertical',
            ]);

            if ($response->failed()) {
                return null;
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Error getting catalog info', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Obtener los feeds del catálogo (WooCommerce, etc.)
     */
    public function getProductFeeds(): array
    {
        try {
            $url = $this->buildUrl("{$this->catalogId}/product_feeds");

            $response = Http::get($url, [
                'access_token' => $this->accessToken,
                'fields' => 'id,name,latest_upload,schedule',
            ]);

            if ($response->failed()) {
                throw new \Exception('No se pudieron obtener los feeds');
            }

            return $response->json()['data'] ?? [];

        } catch (\Exception $e) {
            Log::error('Error getting product feeds', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Forzar actualización de un feed específico
     */
    public function triggerFeedUpdate(string $feedId): bool
    {
        try {
            $url = $this->buildUrl("{$feedId}/uploads");

            $response = Http::post($url, [
                'access_token' => $this->accessToken,
            ]);

            if ($response->successful()) {
                Log::info('Feed update triggered', [
                    'feed_id' => $feedId,
                    'response' => $response->json(),
                ]);
                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Error triggering feed update', [
                'feed_id' => $feedId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
    * Verificar si WooCommerce está correctamente sincronizado con Meta
    */
    public function checkWooCommerceSyncStatus(): array
    {
        try {
            // 1. Verificar conexión al catálogo
            if (!$this->testConnection()) {
                return [
                    'configured' => false,
                    'error' => 'No se puede conectar con el catálogo de Meta',
                ];
            }

            // 2. Obtener feeds configurados
            $feeds = $this->getProductFeeds();

            if (empty($feeds)) {
                return [
                    'configured' => false,
                    'error' => 'No hay feeds configurados en el catálogo',
                ];
            }

            // 3. Buscar feed de WooCommerce
            $wooFeed = collect($feeds)->first(function ($feed) {
                $name = strtolower($feed['name'] ?? '');
                return str_contains($name, 'woo')
                    || str_contains($name, 'wordpress')
                    || str_contains($name, 'test store');
            });

            if (!$wooFeed) {
                return [
                    'configured' => false,
                    'error' => 'No se encontró un feed de WooCommerce',
                    'available_feeds' => $feeds,
                ];
            }

            return [
                'configured' => true,
                'feed' => [
                    'id' => $wooFeed['id'],
                    'name' => $wooFeed['name'],
                    'latest_upload' => $wooFeed['latest_upload'] ?? null,
                    'schedule' => $wooFeed['schedule'] ?? null,
                ],
                'message' => 'WooCommerce está correctamente vinculado a Meta',
            ];

        } catch (\Exception $e) {
            Log::error('Error checking WooCommerce sync status', [
                'error' => $e->getMessage(),
            ]);

            return [
                'configured' => false,
                'error' => $e->getMessage(),
            ];
        }
    }


    /**
     * Forzar sincronización con WooCommerce
     * Busca el feed de WooCommerce y lo actualiza
     */
    public function syncWithWooCommerce(): array
    {
        try {
            $feeds = $this->getProductFeeds();

            if (empty($feeds)) {
                return [
                    'success' => false,
                    'error' => 'No se encontraron feeds configurados',
                    'solution' => 'Configura la integración WooCommerce → Meta en Commerce Manager',
                ];
            }

            // Buscar el feed de WooCommerce
            $wooFeed = collect($feeds)->first(function($feed) {
                $name = strtolower($feed['name'] ?? '');
                return str_contains($name, 'woo') ||
                       str_contains($name, 'test store') ||
                       str_contains($name, 'wordpress');
            });

            if (!$wooFeed) {
                return [
                    'success' => false,
                    'error' => 'No se encontró el feed de WooCommerce',
                    'available_feeds' => array_map(fn($f) => [
                        'id' => $f['id'],
                        'name' => $f['name'] ?? 'Sin nombre',
                    ], $feeds),
                ];
            }

            // Forzar actualización
            $success = $this->triggerFeedUpdate($wooFeed['id']);

            if ($success) {
                return [
                    'success' => true,
                    'feed_id' => $wooFeed['id'],
                    'feed_name' => $wooFeed['name'],
                    'message' => 'Sincronización iniciada. Espera 2-5 minutos.',
                    'next_step' => 'Ejecuta POST /api/products/sync-all para traer productos a tu BD',
                ];
            }

            return [
                'success' => false,
                'error' => 'No se pudo iniciar la sincronización',
            ];

        } catch (\Exception $e) {
            Log::error('Error syncing with WooCommerce', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
