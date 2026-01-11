<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para interactuar con la API REST de WooCommerce
 * Maneja CRUD de productos directamente en tu tienda WooCommerce
 */
class WooCommerceService
{
    private string $storeUrl;
    private string $consumerKey;
    private string $consumerSecret;
    private string $apiVersion;
    private bool $verifySsl;

    public function __construct()
    {
        $this->storeUrl = config('woocommerce.store_url');
        $this->consumerKey = config('woocommerce.consumer_key');
        $this->consumerSecret = config('woocommerce.consumer_secret');
        $this->apiVersion = config('woocommerce.api_version');
        $this->verifySsl = config('woocommerce.verify_ssl');
    }

    /**
     * Construir URL con autenticación OAuth
     */
    private function buildUrl(string $endpoint): string
    {
        return "{$this->storeUrl}/wp-json/{$this->apiVersion}/{$endpoint}";
    }

    /**
     * Headers HTTP con autenticación Basic
     */
    private function getAuthHeaders(): array
    {
        $credentials = base64_encode("{$this->consumerKey}:{$this->consumerSecret}");

        return [
            'Authorization' => "Basic {$credentials}",
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * LISTAR productos de WooCommerce
     */
    public function getProducts(int $page = 1, int $perPage = 100): array
    {
        try {
            $url = $this->buildUrl('products');

            $response = Http::withHeaders($this->getAuthHeaders())
                ->withOptions(['verify' => $this->verifySsl])
                ->get($url, [
                    'page' => $page,
                    'per_page' => $perPage,
                    'status' => 'publish', // Solo productos publicados
                ]);

            if ($response->failed()) {
                throw new \Exception('Error al obtener productos: ' . $response->body());
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('WooCommerce getProducts error', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * OBTENER todos los productos (con paginación automática)
     */
    public function getAllProducts(): array
    {
        $allProducts = [];
        $page = 1;
        $perPage = 100;

        try {
            do {
                $products = $this->getProducts($page, $perPage);
                $allProducts = array_merge($allProducts, $products);
                $page++;
            } while (count($products) === $perPage);

            return $allProducts;

        } catch (\Exception $e) {
            Log::error('WooCommerce getAllProducts error', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * OBTENER un producto específico
     */
    public function getProduct(int $productId): ?array
    {
        try {
            $url = $this->buildUrl("products/{$productId}");

            $response = Http::withHeaders($this->getAuthHeaders())
                ->withOptions(['verify' => $this->verifySsl])
                ->get($url);

            if ($response->failed()) {
                return null;
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('WooCommerce getProduct error', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * CREAR producto en WooCommerce
     */
    public function createProduct(array $data): array
    {
        try {
            $url = $this->buildUrl('products');

            // Estructura de datos para WooCommerce
            $productData = [
                'name' => $data['name'],
                'type' => 'simple',
                'regular_price' => (string) $data['price'],
                'description' => $data['description'] ?? '',
                'short_description' => $data['short_description'] ?? '',
                'status' => $data['is_active'] ?? true ? 'publish' : 'draft',
                'manage_stock' => true,
                'stock_quantity' => $data['stock'] ?? 0,
            ];

            // Agregar imágenes si existen
            if (!empty($data['image_url'])) {
                $productData['images'] = [
                    ['src' => $data['image_url']]
                ];
            }

            $response = Http::withHeaders($this->getAuthHeaders())
                ->withOptions(['verify' => $this->verifySsl])
                ->post($url, $productData);

            if ($response->failed()) {
                throw new \Exception('Error al crear producto: ' . $response->body());
            }

            Log::info('Product created in WooCommerce', [
                'product_id' => $response->json()['id'],
            ]);

            return $response->json();

        } catch (\Exception $e) {
            Log::error('WooCommerce createProduct error', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * ACTUALIZAR producto en WooCommerce
     */
    public function updateProduct(int $productId, array $data): array
    {
        try {
            $url = $this->buildUrl("products/{$productId}");

            $updateData = [];

            if (isset($data['name'])) {
                $updateData['name'] = $data['name'];
            }

            if (isset($data['description'])) {
                $updateData['description'] = $data['description'];
            }

            if (isset($data['price'])) {
                $updateData['regular_price'] = (string) $data['price'];
            }

            if (isset($data['stock'])) {
                $updateData['stock_quantity'] = $data['stock'];
            }

            if (isset($data['is_active'])) {
                $updateData['status'] = $data['is_active'] ? 'publish' : 'draft';
            }

            if (isset($data['image_url'])) {
                $updateData['images'] = [
                    ['src' => $data['image_url']]
                ];
            }

            $response = Http::withHeaders($this->getAuthHeaders())
                ->withOptions(['verify' => $this->verifySsl])
                ->put($url, $updateData);

            if ($response->failed()) {
                throw new \Exception('Error al actualizar producto: ' . $response->body());
            }

            Log::info('Product updated in WooCommerce', [
                'product_id' => $productId,
            ]);

            return $response->json();

        } catch (\Exception $e) {
            Log::error('WooCommerce updateProduct error', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * ELIMINAR producto de WooCommerce
     */
    public function deleteProduct(int $productId, bool $force = false): bool
    {
        try {
            $url = $this->buildUrl("products/{$productId}");

            $response = Http::withHeaders($this->getAuthHeaders())
                ->withOptions(['verify' => $this->verifySsl])
                ->delete($url, [
                    'force' => $force, // true = eliminar permanente, false = enviar a papelera
                ]);

            if ($response->failed()) {
                throw new \Exception('Error al eliminar producto: ' . $response->body());
            }

            Log::info('Product deleted from WooCommerce', [
                'product_id' => $productId,
                'force' => $force,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('WooCommerce deleteProduct error', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * TEST de conexión
     */
    public function testConnection(): bool
    {
        try {
            $url = $this->buildUrl('products');

            $response = Http::withHeaders($this->getAuthHeaders())
                ->withOptions(['verify' => $this->verifySsl])
                ->get($url, ['per_page' => 1]);

            return $response->successful();

        } catch (\Exception $e) {
            Log::error('WooCommerce connection test failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
