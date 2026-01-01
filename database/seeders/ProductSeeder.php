<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'name' => 'Arreglo Frutal Básico',
                'description' => 'Arreglo con fresas, uvas y melón',
                'price' => 25.00,
                'stock' => 10,
                'images' => json_encode(['https://ejemplo.com/basico.jpg']),
                'is_active' => true,
            ],
            [
                'name' => 'Arreglo Frutal Premium',
                'description' => 'Arreglo con fresas cubiertas de chocolate',
                'price' => 45.00,
                'stock' => 5,
                'images' => json_encode(['https://ejemplo.com/premium.jpg']),
                'is_active' => true,
            ],
            [
                'name' => 'Arreglo Frutal Deluxe',
                'description' => 'Arreglo grande con variedad de frutas',
                'price' => 65.00,
                'stock' => 3,
                'images' => json_encode(['https://ejemplo.com/deluxe.jpg']),
                'is_active' => true,
            ],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}
