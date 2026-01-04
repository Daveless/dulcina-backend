<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            'retailer_id' => $metaProduct['retailer_id'] ?? null,
            'name' => $metaProduct['name'] ?? 'Sin nombre',
            'description' => $metaProduct['description'] ?? null,
            'price' => $this->parsePrice($metaProduct['price'] ?? 0),
            'image_url' => $metaProduct['image_url'] ?? null,
            'url' => $metaProduct['url'] ?? null,
            'availability' => $metaProduct['availability'] ?? 'in stock',
        ];
    }

    /**
     * OBTENER productos con todos sus campos
     */
    public function getProducts(int $limit = 25): array
    {
        try {
            $url = $this->buildUrl("{$this->catalogId}/products");

            $response = Http::get($url, [
                'access_token' => $this->accessToken,
                // Incluir retailer_id para identificar productos de WooCommerce
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
     * OBTENER TODOS los productos con paginación automática
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
     * OBTENER un producto específico
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
     * FORZAR sincronización del catálogo desde WooCommerce
     * Esto le dice a Meta que actualice los productos desde la fuente de WooCommerce
     */
    public function triggerWooCommerceSync(): array
    {
        try {
            $url = $this->buildUrl("{$this->catalogId}/product_feeds");

            // Primero obtener el feed de WooCommerce
            $response = Http::get($url, [
                'access_token' => $this->accessToken,
            ]);

            if ($response->failed()) {
                throw new \Exception('No se pudo obtener la lista de feeds');
            }

            $feeds = $response->json()['data'] ?? [];

            // Buscar el feed de WooCommerce
            $wooFeed = collect($feeds)->first(function($feed) {
                return isset($feed['name']) &&
                       (str_contains(strtolower($feed['name']), 'woo') ||
                        str_contains(strtolower($feed['name']), 'test store'));
            });

            if (!$wooFeed) {
                throw new \Exception('No se encontró el feed de WooCommerce');
            }

            // Forzar actualización del feed
            $feedId = $wooFeed['id'];
            $updateUrl = $this->buildUrl("{$feedId}");

            $updateResponse = Http::post($updateUrl, [
                'access_token' => $this->accessToken,
                'update_only' => false, // Esto fuerza una sincronización completa
            ]);

            if ($updateResponse->failed()) {
                Log::error('Failed to trigger sync', [
                    'feed_id' => $feedId,
                    'response' => $updateResponse->json(),
                ]);

                throw new \Exception('No se pudo iniciar la sincronización');
            }

            Log::info('WooCommerce sync triggered', [
                'feed_id' => $feedId,
                'feed_name' => $wooFeed['name'],
            ]);

            return [
                'success' => true,
                'feed_id' => $feedId,
                'feed_name' => $wooFeed['name'],
                'message' => 'Sincronización iniciada. Los productos se actualizarán en breve.',
            ];

        } catch (\Exception $e) {
            Log::error('Error triggering WooCommerce sync', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * ELIMINAR productos creados directamente (no de WooCommerce)
     * Útil para limpiar productos duplicados
     */
    public function deleteNonWooCommerceProducts(): array
    {
        try {
            $allProducts = $this->getAllProducts();
            $deleted = [];
            $errors = [];

            foreach ($allProducts as $product) {
                // Identificar productos NO de WooCommerce
                // Los de WooCommerce tienen retailer_id numérico simple (ej: "1804")
                // Los creados por tu código tienen formato "product_timestamp_random"

                $retailerId = $product['retailer_id'] ?? '';

                if (str_starts_with($retailerId, 'product_')) {
                    // Este es un producto creado por tu código, eliminarlo
                    try {
                        $success = $this->batchDeleteProduct($product['meta_product_id'], $retailerId);

                        if ($success) {
                            $deleted[] = [
                                'id' => $product['meta_product_id'],
                                'name' => $product['name'],
                                'retailer_id' => $retailerId,
                            ];
                        }
                    } catch (\Exception $e) {
                        $errors[] = [
                            'id' => $product['meta_product_id'],
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            return [
                'deleted_count' => count($deleted),
                'error_count' => count($errors),
                'deleted' => $deleted,
                'errors' => $errors,
            ];

        } catch (\Exception $e) {
            Log::error('Error cleaning non-WooCommerce products', [
                'error' => $e->getMessage(),
            ]);

            return [
                'deleted_count' => 0,
                'error_count' => 1,
                'errors' => [$e->getMessage()],
            ];
        }
    }

    /**
     * Batch API para eliminar un producto
     */
    private function batchDeleteProduct(string $metaProductId, string $retailerId): bool
    {
        try {
            $url = $this->buildUrl("{$this->catalogId}/items_batch");

            $response = Http::asForm()->post($url, [
                'access_token' => $this->accessToken,
                'requests' => json_encode([
                    [
                        'method' => 'DELETE',
                        'retailer_id' => $retailerId,
                    ]
                ]),
            ]);

            if ($response->failed()) {
                Log::error('Batch delete failed', [
                    'product_id' => $metaProductId,
                    'retailer_id' => $retailerId,
                    'response' => $response->json(),
                ]);
                return false;
            }

            $result = $response->json();
            $handle = $result['handles'][0] ?? null;

            if (!$handle) {
                return false;
            }

            // Esperar un momento y verificar el status
            sleep(2);

            $statusUrl = $this->buildUrl("{$this->catalogId}/check_batch_request_status");
            $statusResponse = Http::get($statusUrl, [
                'access_token' => $this->accessToken,
                'handle' => $handle,
            ]);

            $status = $statusResponse->json();

            Log::info('Batch delete status', [
                'handle' => $handle,
                'status' => $status,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Error in batchDeleteProduct', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * NO USAR - Mantener solo para compatibilidad
     * Los productos deben crearse desde WooCommerce
     */
    public function createProduct(array $productData): ?array
    {
        Log::warning('createProduct called - Products should be created in WooCommerce instead');

        throw new \Exception(
            'No se pueden crear productos directamente en Meta. ' .
            'Por favor, crea los productos en WooCommerce y sincroniza el catálogo.'
        );
    }

    /**
     * NO USAR - La actualización se hace desde WooCommerce
     */
    public function updateProduct(string $metaProductId, array $productData): bool
    {
        Log::warning('updateProduct called - Products should be updated in WooCommerce instead');
        return true;
    }

    /**
     * NO USAR - La eliminación se hace desde WooCommerce
     */
    public function deleteProduct(string $metaProductId): bool
    {
        Log::warning('deleteProduct called - Products should be deleted in WooCommerce instead');
        return true;
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
     * Obtener los feeds configurados (incluyendo WooCommerce)
     */
    public function getProductFeeds(): array
    {
        try {
            $url = $this->buildUrl("{$this->catalogId}/product_feeds");

            $response = Http::get($url, [
                'access_token' => $this->accessToken,
                'fields' => 'id,name,latest_upload',
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
}
