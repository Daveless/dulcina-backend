<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Services\MetaCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * Listar todos los productos
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
     * Crear un nuevo producto (local + Meta)
     */
    public function store(StoreProductRequest $request, MetaCatalogService $metaService)
    {
        DB::beginTransaction();

        try {
            // Primero crear en Meta
            $metaResponse = $metaService->createProduct([
                'name' => $request->name,
                'description' => $request->description,
                'price' => $request->price,
                'image_url' => $request->image_url,
                'is_active' => $request->is_active ?? true,
                'url' => $request->image_url, // URL del producto (temporal)
            ]);

            if (!$metaResponse || !isset($metaResponse['id'])) {
                throw new \Exception('No se pudo crear el producto en Meta');
            }

            // Luego crear localmente con el ID de Meta
            $product = Product::create([
                'meta_product_id' => $metaResponse['id'],
                'retailer_id' => $metaResponse['retailer_id'] ?? null,
                'name' => $request->name,
                'description' => $request->description,
                'price' => $request->price,
                'images' => $request->image_url ? json_encode([$request->image_url]) : json_encode([]),
                'stock' => $request->stock,
                'is_active' => $request->is_active ?? true,
                'last_synced_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Producto creado exitosamente',
                'product' => $product,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al crear el producto',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ver un producto específico
     */
    public function show(Product $product)
    {
        return response()->json($product);
    }

    /**
     * Actualizar un producto (local + Meta)
     */
    public function update(UpdateProductRequest $request, Product $product, MetaCatalogService $metaService)
    {
        DB::beginTransaction();

        try {
            // Si tiene meta_product_id, actualizar en Meta
            if ($product->meta_product_id) {
                $updateData = $request->only(['name', 'description', 'price', 'is_active']);

                if ($request->has('image_url')) {
                    $updateData['image_url'] = $request->image_url;
                }

                $success = $metaService->updateProduct($product->meta_product_id, $updateData);

                if (!$success) {
                    throw new \Exception('No se pudo actualizar el producto en Meta');
                }
            }

            // Actualizar localmente
            $productData = $request->only(['name', 'description', 'price', 'stock', 'is_active']);

            if ($request->has('image_url')) {
                $productData['images'] = json_encode([$request->image_url]);
            }

            $productData['last_synced_at'] = now();

            $product->update($productData);

            DB::commit();

            return response()->json([
                'message' => 'Producto actualizado exitosamente',
                'product' => $product->fresh(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al actualizar el producto',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar un producto (local + Meta)
     */
    public function destroy(Product $product, MetaCatalogService $metaService)
    {
        DB::beginTransaction();

        try {
            // Si tiene meta_product_id, eliminar de Meta
            if ($product->meta_product_id) {
                $success = $metaService->deleteProduct($product->meta_product_id);

                if (!$success) {
                    throw new \Exception('No se pudo eliminar el producto de Meta');
                }
            }

            // Eliminar localmente
            $product->delete();

            DB::commit();

            return response()->json([
                'message' => 'Producto eliminado exitosamente',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al eliminar el producto',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sincronizar con Meta y ELIMINAR productos no sincronizados
     */
    public function syncWithMeta(MetaCatalogService $metaService)
    {
        try {
            $metaProducts = $metaService->getAllProducts();

            if (empty($metaProducts)) {
                return response()->json([
                    'message' => 'No se encontraron productos para sincronizar',
                    'created' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                    'total' => 0,
                ]);
            }

            $created = 0;
            $updated = 0;
            $deleted = 0;
            $errors = [];

            DB::beginTransaction();

            // Obtener IDs de productos en Meta
            $metaProductIds = collect($metaProducts)->pluck('meta_product_id')->toArray();

            // Sincronizar productos de Meta
            foreach ($metaProducts as $metaProduct) {
                try {
                    $product = Product::updateOrCreate(
                        ['meta_product_id' => $metaProduct['meta_product_id']],
                        [
                            'name' => $metaProduct['name'],
                            'description' => $metaProduct['description'],
                            'price' => $metaProduct['price'],
                            'images' => $metaProduct['image_url'] ? json_encode([$metaProduct['image_url']]) : json_encode([]),
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
                        'product_id' => $metaProduct['meta_product_id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // ELIMINAR productos locales que NO están en Meta
            $productsToDelete = Product::whereNotNull('meta_product_id')
                ->whereNotIn('meta_product_id', $metaProductIds)
                ->get();

            foreach ($productsToDelete as $productToDelete) {
                $productToDelete->delete();
                $deleted++;
            }

            DB::commit();

            return response()->json([
                'message' => 'Sincronización completada',
                'created' => $created,
                'updated' => $updated,
                'deleted' => $deleted,
                'errors' => count($errors),
                'total' => count($metaProducts),
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
     * Verificar conexión con Meta
     */
    public function testMetaConnection(MetaCatalogService $metaService)
    {
        $isConnected = $metaService->testConnection();

        if ($isConnected) {
            $catalogInfo = $metaService->getCatalogInfo();

            return response()->json([
                'status' => 'connected',
                'catalog' => $catalogInfo,
            ]);
        }

        return response()->json([
            'status' => 'disconnected',
            'message' => 'No se pudo conectar con Meta API',
        ], 500);
    }
}
