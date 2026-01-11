<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Services\WooCommerceService;
use App\Services\MetaCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controlador de Productos
 * Flujo: WooCommerce → Meta → Base de datos local
 */
class ProductController extends Controller
{
    /**
     * LISTAR productos locales (sincronizados desde Meta)
     */
    public function index(Request $request)
    {
        $query = Product::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $products = $query->orderBy('name')->paginate(20);

        return response()->json($products);
    }

    /**
     * VER producto específico
     */
    public function show(Product $product)
    {
        return response()->json($product);
    }

    /**
     * CREAR producto en WooCommerce
     * Después debes sincronizar: WooCommerce → Meta → Local
     */
    public function store(StoreProductRequest $request, WooCommerceService $wooService)
    {
        try {
            // Crear en WooCommerce
            $wooProduct = $wooService->createProduct([
                'name' => $request->name,
                'description' => $request->description,
                'price' => $request->price,
                'stock' => $request->stock,
                'is_active' => $request->is_active ?? true,
                'image_url' => $request->image_url,
            ]);

            return response()->json([
                'message' => 'Producto creado en WooCommerce',
                'woocommerce_id' => $wooProduct['id'],
                'product' => $wooProduct,
                'next_steps' => [
                    '1. Espera 2-5 minutos para que WooCommerce sincronice con Meta',
                    '2. Ejecuta POST /api/products/sync-all para traer a tu base de datos',
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear producto',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ACTUALIZAR producto en WooCommerce
     * Requiere tener el woocommerce_id en la base de datos local
     */
    public function update(UpdateProductRequest $request, Product $product, WooCommerceService $wooService)
    {
        try {
            // Verificar que el producto tenga retailer_id (ID de WooCommerce)
            if (!$product->retailer_id) {
                return response()->json([
                    'message' => 'Este producto no tiene ID de WooCommerce',
                    'error' => 'No se puede actualizar en WooCommerce',
                ], 400);
            }

            $wooId = (int) $product->retailer_id;

            // Actualizar en WooCommerce
            $wooProduct = $wooService->updateProduct($wooId, [
                'name' => $request->name,
                'description' => $request->description,
                'price' => $request->price,
                'stock' => $request->stock,
                'is_active' => $request->is_active,
                'image_url' => $request->image_url,
            ]);

            // Actualizar localmente
            $product->update([
                'name' => $request->name,
                'description' => $request->description,
                'price' => $request->price,
                'stock' => $request->stock,
                'is_active' => $request->is_active ?? true,
                'images' => $request->image_url ? json_encode([$request->image_url]) : $product->images,
            ]);

            return response()->json([
                'message' => 'Producto actualizado en WooCommerce y localmente',
                'product' => $product->fresh(),
                'woocommerce' => $wooProduct,
                'note' => 'Los cambios se sincronizarán automáticamente con Meta',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar producto',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ELIMINAR producto de WooCommerce
     */
    public function destroy(Product $product, WooCommerceService $wooService)
    {
        try {
            if (!$product->retailer_id) {
                // Si no tiene ID de WooCommerce, solo eliminar localmente
                $product->delete();

                return response()->json([
                    'message' => 'Producto eliminado localmente (no existía en WooCommerce)',
                ]);
            }

            $wooId = (int) $product->retailer_id;

            // Eliminar de WooCommerce (permanentemente)
            $success = $wooService->deleteProduct($wooId, true);

            if (!$success) {
                throw new \Exception('No se pudo eliminar de WooCommerce');
            }

            // Eliminar localmente
            $product->delete();

            return response()->json([
                'message' => 'Producto eliminado de WooCommerce y localmente',
                'note' => 'Se eliminará automáticamente de Meta en la próxima sincronización',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar producto',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * SINCRONIZACIÓN COMPLETA
     * WooCommerce → Meta → Base de datos local
     */
    public function syncAll(WooCommerceService $wooService, MetaCatalogService $metaService)
    {
        try {
            // Paso 1: Obtener productos de Meta (que vienen de WooCommerce)
            $metaProducts = $metaService->getAllProducts();

            if (empty($metaProducts)) {
                return response()->json([
                    'message' => 'No se encontraron productos en Meta',
                    'note' => 'Verifica que WooCommerce esté sincronizando correctamente',
                ], 404);
            }

            $created = 0;
            $updated = 0;
            $deleted = 0;
            $errors = [];

            DB::beginTransaction();

            // IDs de productos en Meta
            $metaProductIds = collect($metaProducts)->pluck('meta_product_id')->toArray();

            // Sincronizar cada producto
            foreach ($metaProducts as $metaProduct) {
                try {
                    $product = Product::updateOrCreate(
                        ['meta_product_id' => $metaProduct['meta_product_id']],
                        [
                            'retailer_id' => $metaProduct['retailer_id'],
                            'name' => $metaProduct['name'],
                            'description' => $metaProduct['description'],
                            'price' => $metaProduct['price'],
                            'images' => $metaProduct['image_url']
                                ? json_encode([$metaProduct['image_url']])
                                : json_encode([]),
                            'is_active' => $metaProduct['availability'] === 'in stock',
                            'last_synced_at' => now(),
                        ]
                    );

                    if ($product->wasRecentlyCreated) {
                        $created++;
                    } else {
                        $updated++;
                    }

                } catch (\Exception $e) {
                    $errors[] = [
                        'product_id' => $metaProduct['meta_product_id'],
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // Eliminar productos locales que ya no están en Meta
            $productsToDelete = Product::whereNotNull('meta_product_id')
                ->whereNotIn('meta_product_id', $metaProductIds)
                ->get();

            foreach ($productsToDelete as $productToDelete) {
                $productToDelete->delete();
                $deleted++;
            }

            DB::commit();

            return response()->json([
                'message' => 'Sincronización completa exitosa',
                'created' => $created,
                'updated' => $updated,
                'deleted' => $deleted,
                'total' => count($metaProducts),
                'errors' => count($errors),
                'error_details' => $errors,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error en la sincronización',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * TEST de conexiones
     */
    public function testConnections(WooCommerceService $wooService, MetaCatalogService $metaService)
    {
        $wooConnected = $wooService->testConnection();
        $metaConnected = $metaService->testConnection();

        return response()->json([
            'woocommerce' => [
                'status' => $wooConnected ? 'connected' : 'disconnected',
                'url' => config('woocommerce.store_url'),
            ],
            'meta' => [
                'status' => $metaConnected ? 'connected' : 'disconnected',
                'catalog_id' => config('meta.catalog_id'),
            ],
            'overall_status' => $wooConnected && $metaConnected ? 'ready' : 'configuration_needed',
        ]);
    }

    /**
     * LISTAR productos de WooCommerce (sin guardar)
     */
    public function listWooProducts(WooCommerceService $wooService)
    {
        try {
            $products = $wooService->getProducts(1, 20);

            return response()->json([
                'products' => $products,
                'count' => count($products),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener productos de WooCommerce',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ver los feeds configurados en Meta
     */
    public function getMetaFeeds(MetaCatalogService $metaService)
    {
        try {
            $feeds = $metaService->getProductFeeds();

            return response()->json([
                'feeds' => $feeds,
                'count' => count($feeds),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener feeds',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verificar estado de sincronización WooCommerce → Meta
     */
    public function checkWooSyncStatus(MetaCatalogService $metaService)
    {
        try {
            $result = $metaService->checkWooCommerceSyncStatus();
            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'configured' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
