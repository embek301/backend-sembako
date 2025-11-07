<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;

class ProductSeeder extends Seeder
{
    public function run()
    {
        $products = [
            // BERAS (Category 1)
            [
                'category_id' => 1,
                'name' => 'Beras Premium 5kg',
                'description' => 'Beras premium pilihan kualitas terbaik, pulen dan wangi',
                'price' => 65000,
                'stock' => 100,
                'unit' => 'kg',
                'sku' => 'BERAS-001',
                'min_order' => 1,
                'is_active' => true
            ],
            [
                'category_id' => 1,
                'name' => 'Beras Pera 10kg',
                'description' => 'Beras pera cocok untuk nasi goreng dan nasi uduk',
                'price' => 120000,
                'stock' => 50,
                'unit' => 'kg',
                'sku' => 'BERAS-002',
                'min_order' => 1,
                'is_active' => true
            ],
            [
                'category_id' => 1,
                'name' => 'Beras Pulen 25kg',
                'description' => 'Beras pulen kualitas super untuk keluarga besar',
                'price' => 280000,
                'stock' => 30,
                'unit' => 'kg',
                'sku' => 'BERAS-003',
                'min_order' => 1,
                'is_active' => true
            ],
            
            // MINYAK GORENG (Category 2)
            [
                'category_id' => 2,
                'name' => 'Minyak Goreng 2L',
                'description' => 'Minyak goreng kemasan 2 liter, jernih dan berkualitas',
                'price' => 32000,
                'stock' => 200,
                'unit' => 'liter',
                'sku' => 'MINYAK-001',
                'min_order' => 1,
                'is_active' => true
            ],
            [
                'category_id' => 2,
                'name' => 'Minyak Goreng 5L',
                'description' => 'Minyak goreng kemasan 5 liter, hemat untuk rumah tangga',
                'price' => 78000,
                'stock' => 80,
                'unit' => 'liter',
                'sku' => 'MINYAK-002',
                'min_order' => 1,
                'is_active' => true
            ],
            
            // GULA (Category 3)
            [
                'category_id' => 3,
                'name' => 'Gula Pasir 1kg',
                'description' => 'Gula pasir putih berkualitas premium',
                'price' => 15000,
                'stock' => 150,
                'unit' => 'kg',
                'sku' => 'GULA-001',
                'min_order' => 1,
                'is_active' => true
            ],
            [
                'category_id' => 3,
                'name' => 'Gula Aren 500g',
                'description' => 'Gula aren asli organik, cocok untuk minuman',
                'price' => 35000,
                'stock' => 60,
                'unit' => 'gram',
                'sku' => 'GULA-002',
                'min_order' => 1,
                'is_active' => true
            ],
            
            // TEPUNG (Category 4)
            [
                'category_id' => 4,
                'name' => 'Tepung Terigu 1kg',
                'description' => 'Tepung terigu serbaguna untuk kue dan masakan',
                'price' => 12000,
                'stock' => 100,
                'unit' => 'kg',
                'sku' => 'TEPUNG-001',
                'min_order' => 1,
                'is_active' => true
            ],
            [
                'category_id' => 4,
                'name' => 'Tepung Beras 500g',
                'description' => 'Tepung beras halus untuk kue tradisional',
                'price' => 10000,
                'stock' => 80,
                'unit' => 'gram',
                'sku' => 'TEPUNG-002',
                'min_order' => 1,
                'is_active' => true
            ],
            
            // BUMBU (Category 5)
            [
                'category_id' => 5,
                'name' => 'Bawang Merah 1kg',
                'description' => 'Bawang merah segar pilihan',
                'price' => 35000,
                'stock' => 80,
                'unit' => 'kg',
                'sku' => 'BUMBU-001',
                'min_order' => 1,
                'is_active' => true
            ],
            [
                'category_id' => 5,
                'name' => 'Bawang Putih 500g',
                'description' => 'Bawang putih segar berkualitas',
                'price' => 25000,
                'stock' => 60,
                'unit' => 'gram',
                'sku' => 'BUMBU-002',
                'min_order' => 1,
                'is_active' => true
            ],
            [
                'category_id' => 5,
                'name' => 'Cabai Merah 250g',
                'description' => 'Cabai merah segar dan pedas',
                'price' => 18000,
                'stock' => 50,
                'unit' => 'gram',
                'sku' => 'BUMBU-003',
                'min_order' => 1,
                'is_active' => true
            ],
            
            // MIE INSTAN (Category 6)
            [
                'category_id' => 6,
                'name' => 'Mie Instan Goreng isi 5',
                'description' => 'Paket mie instan goreng rasa ayam bawang',
                'price' => 12500,
                'stock' => 300,
                'unit' => 'pack',
                'sku' => 'MIE-001',
                'min_order' => 1,
                'is_active' => true
            ],
            [
                'category_id' => 6,
                'name' => 'Mie Instan Kuah isi 5',
                'description' => 'Paket mie instan kuah rasa soto',
                'price' => 11500,
                'stock' => 250,
                'unit' => 'pack',
                'sku' => 'MIE-002',
                'min_order' => 1,
                'is_active' => true
            ],
            
            // TELUR (Category 7)
            [
                'category_id' => 7,
                'name' => 'Telur Ayam 1kg',
                'description' => 'Telur ayam segar ukuran sedang, isi sekitar 15-17 butir',
                'price' => 28000,
                'stock' => 100,
                'unit' => 'kg',
                'sku' => 'TELUR-001',
                'min_order' => 1,
                'is_active' => true
            ],
            [
                'category_id' => 7,
                'name' => 'Telur Ayam Omega 1kg',
                'description' => 'Telur ayam omega tinggi nutrisi',
                'price' => 35000,
                'stock' => 50,
                'unit' => 'kg',
                'sku' => 'TELUR-002',
                'min_order' => 1,
                'is_active' => true
            ],
            
            // SUSU (Category 8)
            [
                'category_id' => 8,
                'name' => 'Susu UHT 1L',
                'description' => 'Susu UHT full cream kemasan tetra pack',
                'price' => 18000,
                'stock' => 150,
                'unit' => 'liter',
                'sku' => 'SUSU-001',
                'min_order' => 1,
                'is_active' => true
            ],
            [
                'category_id' => 8,
                'name' => 'Susu Bubuk 400g',
                'description' => 'Susu bubuk full cream untuk seluruh keluarga',
                'price' => 45000,
                'stock' => 80,
                'unit' => 'gram',
                'sku' => 'SUSU-002',
                'min_order' => 1,
                'is_active' => true
            ],
            
            // MINUMAN (Category 9)
            [
                'category_id' => 9,
                'name' => 'Air Mineral 600ml isi 24',
                'description' => '1 dus air mineral kemasan 600ml',
                'price' => 48000,
                'stock' => 100,
                'unit' => 'dus',
                'sku' => 'MINUM-001',
                'min_order' => 1,
                'is_active' => true
            ],
            [
                'category_id' => 9,
                'name' => 'Teh Kemasan 500ml isi 12',
                'description' => 'Teh manis kemasan botol',
                'price' => 36000,
                'stock' => 80,
                'unit' => 'pack',
                'sku' => 'MINUM-002',
                'min_order' => 1,
                'is_active' => true
            ],
            
            // SNACK (Category 10)
            [
                'category_id' => 10,
                'name' => 'Keripik Singkong 250g',
                'description' => 'Keripik singkong renyah aneka rasa',
                'price' => 15000,
                'stock' => 120,
                'unit' => 'pack',
                'sku' => 'SNACK-001',
                'min_order' => 1,
                'is_active' => true
            ],
            [
                'category_id' => 10,
                'name' => 'Biskuit Kaleng 350g',
                'description' => 'Biskuit premium dalam kaleng, tahan lama',
                'price' => 28000,
                'stock' => 60,
                'unit' => 'kaleng',
                'sku' => 'SNACK-002',
                'min_order' => 1,
                'is_active' => true
            ],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}