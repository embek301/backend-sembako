<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    public function run()
    {
        $categories = [
            ['name' => 'Beras', 'description' => 'Berbagai jenis beras berkualitas', 'icon' => '🌾'],
            ['name' => 'Minyak Goreng', 'description' => 'Minyak goreng kemasan', 'icon' => '🛢️'],
            ['name' => 'Gula', 'description' => 'Gula pasir dan gula aren', 'icon' => '🧂'],
            ['name' => 'Tepung', 'description' => 'Tepung terigu dan tepung beras', 'icon' => '🍚'],
            ['name' => 'Bumbu Dapur', 'description' => 'Berbagai bumbu masakan', 'icon' => '🧄'],
            ['name' => 'Mie Instan', 'description' => 'Mie instan berbagai merk', 'icon' => '🍜'],
            ['name' => 'Telur', 'description' => 'Telur ayam segar', 'icon' => '🥚'],
            ['name' => 'Susu', 'description' => 'Susu kemasan dan bubuk', 'icon' => '🥛'],
            ['name' => 'Minuman', 'description' => 'Minuman kemasan dan segar', 'icon' => '🥤'],
            ['name' => 'Snack', 'description' => 'Cemilan dan makanan ringan', 'icon' => '🍿'],
        ];

        foreach ($categories as $category) {
            Category::create($category);
        }
    }
}