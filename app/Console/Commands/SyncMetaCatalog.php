<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\MetaCatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncMetaCatalog extends Command
{
    protected $signature = 'meta:sync-catalog {--force : Forzar sincronización completa}';
    protected $description = 'Sincroniza los productos desde el catálogo de Meta';

    public function handle(MetaCatalogService $metaService): int
    {
        $this->info('🔄 Iniciando sincronización con Meta Catalog...');

        // Verificar conexión
        if (!$metaService->testConnection()) {
            $this->error('❌ Error: No se pudo conectar con Meta API. Verifica tus credenciales.');
            return self::FAILURE;
        }

        $this->info('✅ Conexión con Meta establecida');

        // Obtener info del catálogo
        $catalogInfo = $metaService->getCatalogInfo();
        if ($catalogInfo) {
            $this->info("📦 Catálogo: {$catalogInfo['name']}");
            $this->info("📊 Total de productos en Meta: {$catalogInfo['product_count']}");
        }

        $this->newLine();

        try {
            // Obtener todos los productos de Meta
            $this->info('📥 Descargando productos de Meta...');
            $metaProducts = $metaService->getAllProducts();

            $this->info("✅ " . count($metaProducts) . " productos descargados");
            $this->newLine();

            if (empty($metaProducts)) {
                $this->warn('⚠️  No se encontraron productos para sincronizar');
                return self::SUCCESS;
            }

            // Crear barra de progreso
            $bar = $this->output->createProgressBar(count($metaProducts));
            $bar->start();

            $created = 0;
            $updated = 0;
            $errors = 0;

            DB::beginTransaction();

            foreach ($metaProducts as $metaProduct) {
                try {
                    // Buscar si el producto ya existe
                    $product = Product::where('meta_product_id', $metaProduct['meta_product_id'])->first();

                    // Preparar datos para guardar
                    $productData = [
                        'meta_product_id' => $metaProduct['meta_product_id'],
                        'name' => $metaProduct['name'],
                        'description' => $metaProduct['description'],
                        'price' => $metaProduct['price'],
                        'images' => $metaProduct['image_url'] ? json_encode([$metaProduct['image_url']]) : json_encode([]),
                        'is_active' => $metaProduct['availability'] === 'in stock',
                        'last_synced_at' => now(),
                    ];

                    if ($product) {
                        // Actualizar producto existente
                        $product->update($productData);
                        $updated++;
                    } else {
                        // Crear nuevo producto
                        Product::create($productData);
                        $created++;
                    }

                } catch (\Exception $e) {
                    $errors++;
                    $this->newLine();
                    $this->error("Error procesando producto {$metaProduct['meta_product_id']}: {$e->getMessage()}");

                    // Log detallado para debugging
                    Log::error('Error syncing product', [
                        'product' => $metaProduct,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }

                $bar->advance();
            }

            DB::commit();

            $bar->finish();
            $this->newLine(2);

            // Resumen
            $this->info('✅ Sincronización completada');
            $this->table(
                ['Acción', 'Cantidad'],
                [
                    ['Creados', $created],
                    ['Actualizados', $updated],
                    ['Errores', $errors],
                    ['Total procesados', $created + $updated],
                ]
            );

            return self::SUCCESS;

        } catch (\Exception $e) {
            DB::rollBack();

            $this->error('❌ Error durante la sincronización: ' . $e->getMessage());
            $this->error('Detalles: ' . $e->getTraceAsString());

            return self::FAILURE;
        }
    }
}
