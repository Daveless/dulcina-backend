<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Response;

/**
 * Controlador para generar feed CSV para Meta Catalog
 * Este endpoint es PÚBLICO y será accedido por Meta periódicamente
 */
class MetaFeedController extends Controller
{
    /**
     * Generar CSV feed con todos los productos
     * URL: https://tu-dominio.com/meta-feed/products.csv
     */
    public function generateCSV()
    {
        // Obtener productos activos de WooCommerce (tienen retailer_id)
        $products = Product::whereNotNull('retailer_id')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        // Headers del CSV según especificación de Meta
        $headers = [
            'id',                    // retailer_id (único)
            'title',                 // Nombre del producto
            'description',           // Descripción
            'availability',          // in stock / out of stock
            'condition',             // new / refurbished / used
            'price',                 // Precio con moneda (ej: 45.00 USD)
            'link',                  // URL del producto en tu sitio
            'image_link',            // URL de la imagen principal
            'brand',                 // Marca (opcional)
        ];

        // Crear contenido CSV
        $csv = [];

        // Primera línea: headers
        $csv[] = implode(',', $headers);

        // Agregar cada producto
        foreach ($products as $product) {
            $images = json_decode($product->images, true) ?? [];
            $firstImage = $images[0] ?? '';

            $row = [
                $this->escapeCsv($product->retailer_id),                          // id
                $this->escapeCsv($product->name),                                 // title
                $this->escapeCsv($product->description ?? ''),                    // description
                $product->stock > 0 ? 'in stock' : 'out of stock',               // availability
                'new',                                                            // condition
                number_format($product->price, 2, '.', '') . ' USD',             // price
                $this->escapeCsv(config('app.url') . '/product/' . $product->id), // link
                $this->escapeCsv($firstImage),                                    // image_link
                $this->escapeCsv(config('app.name', 'Dulcina')),                 // brand
            ];

            $csv[] = implode(',', $row);
        }

        // Unir todas las líneas
        $csvContent = implode("\n", $csv);

        // Retornar como CSV con headers apropiados
        return response($csvContent, 200)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'inline; filename="products.csv"')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Escapar valores para CSV (manejar comas, comillas, etc.)
     */
    private function escapeCsv(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // Si contiene comas, comillas o saltos de línea, envolver en comillas
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            // Duplicar comillas internas
            $value = str_replace('"', '""', $value);
            return '"' . $value . '"';
        }

        return $value;
    }

    /**
     * Endpoint de prueba para ver el feed en formato JSON
     */
    public function preview()
    {
        $products = Product::whereNotNull('retailer_id')
            ->where('is_active', true)
            ->get()
            ->map(function($product) {
                $images = json_decode($product->images, true) ?? [];

                return [
                    'id' => $product->retailer_id,
                    'title' => $product->name,
                    'description' => $product->description,
                    'availability' => $product->stock > 0 ? 'in stock' : 'out of stock',
                    'condition' => 'new',
                    'price' => number_format($product->price, 2, '.', '') . ' USD',
                    'link' => config('app.url') . '/product/' . $product->id,
                    'image_link' => $images[0] ?? '',
                    'brand' => config('app.name', 'Dulcina'),
                ];
            });

        return response()->json([
            'total_products' => $products->count(),
            'products' => $products,
            'csv_url' => url('/meta-feed/products.csv'),
            'note' => 'Usa csv_url en Meta Commerce Manager para el feed schedule',
        ]);
    }
}
