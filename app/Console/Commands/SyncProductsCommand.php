<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\MetaCatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando para sincronizar productos desde Meta a la base de datos local
 * Ejecutar: php artisan products:sync
 */
class SyncProductsCommand extends Command
{
    protected $signature = 'products:sync';
    protected $description = 'Sincronizar productos desde Meta Catalog a la base de datos local';

    public function handle(MetaCatalogService $metaService): int
    {
        $this->info('🔄 Iniciando sincronización de productos...');
        $this->newLine();

        try {
            // Obtener productos de Meta
            $this->info('📥 Obteniendo productos desde Meta Catalog...');
            $metaProducts = $metaService->getAllProducts();

            if (empty($metaProducts)) {
                $this->warn('⚠️  No se encontraron productos en Meta Catalog');
                return self::FAILURE;
            }

            $this->info("✓ Se encontraron " . count($metaProducts) . " productos en Meta");
            $this->newLine();

            $created = 0;
            $updated = 0;
            $deleted = 0;
            $errors = 0;

            DB::beginTransaction();

            // Barra de progreso
            $bar = $this->output->createProgressBar(count($metaProducts));
            $bar->start();

            $metaProductIds = collect($metaProducts)->pluck('meta_product_id')->toArray();

            // Sincronizar productos
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
                    $errors++;
                    $this->newLine();
                    $this->error("Error en producto {$metaProduct['meta_product_id']}: {$e->getMessage()}");
                }

                $bar->advance();
            }

            // Eliminar productos que ya no están en Meta
            $productsToDelete = Product::whereNotNull('meta_product_id')
                ->whereNotIn('meta_product_id', $metaProductIds)
                ->get();

            foreach ($productsToDelete as $productToDelete) {
                $productToDelete->delete();
                $deleted++;
            }

            $bar->finish();
            DB::commit();

            $this->newLine(2);
            $this->info('✅ Sincronización completada exitosamente');
            $this->newLine();

            // Tabla de resultados
            $this->table(
                ['Métrica', 'Cantidad'],
                [
                    ['Productos creados', $created],
                    ['Productos actualizados', $updated],
                    ['Productos eliminados', $deleted],
                    ['Errores', $errors],
                    ['Total en Meta', count($metaProducts)],
                ]
            );

            return self::SUCCESS;

        } catch (\Exception $e) {
            DB::rollBack();

            $this->newLine();
            $this->error('❌ Error en la sincronización: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
