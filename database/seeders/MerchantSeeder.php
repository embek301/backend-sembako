<?php

// database/seeders/MerchantSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Product;
use Illuminate\Support\Facades\Hash;

class MerchantSeeder extends Seeder
{
    public function run()
    {
        // Create merchants
        $merchant1 = User::create([
            'name' => 'Toko Sembako Makmur',
            'email' => 'makmur@merchant.com',
            'phone' => '081234567895',
            'password' => Hash::make('password'),
            'role' => 'merchant',
            'status' => 'active',
            'store_name' => 'Toko Sembako Makmur',
            'store_description' => 'Menyediakan berbagai kebutuhan sembako berkualitas dengan harga terjangkau',
            'bank_name' => 'BCA',
            'bank_account_number' => '1234567890',
            'bank_account_name' => 'Toko Sembako Makmur',
            'commission_rate' => 10,
            'is_verified' => true,
            'verified_at' => now()
        ]);

        $merchant2 = User::create([
            'name' => 'Warung Bu Siti',
            'email' => 'busiti@merchant.com',
            'phone' => '081234567896',
            'password' => Hash::make('password'),
            'role' => 'merchant',
            'status' => 'active',
            'store_name' => 'Warung Bu Siti',
            'store_description' => 'Warung sembako tradisional dengan pelayanan ramah',
            'bank_name' => 'BNI',
            'bank_account_number' => '0987654321',
            'bank_account_name' => 'Siti Aminah',
            'commission_rate' => 12,
            'is_verified' => true,
            'verified_at' => now()
        ]);

        $merchant3 = User::create([
            'name' => 'Toko Grosir Sentosa',
            'email' => 'sentosa@merchant.com',
            'phone' => '081234567897',
            'password' => Hash::make('password'),
            'role' => 'merchant',
            'status' => 'inactive', // Not verified yet
            'store_name' => 'Toko Grosir Sentosa',
            'store_description' => 'Grosir sembako harga grosir untuk umum',
            'bank_name' => 'Mandiri',
            'bank_account_number' => '5555666677',
            'bank_account_name' => 'CV Sentosa Jaya',
            'commission_rate' => 10,
            'is_verified' => false,
            'verified_at' => null
        ]);

        // Update existing products to assign to merchants
        // Assign products 1-7 to merchant1
        Product::whereIn('id', [1, 2, 3, 4, 5, 6, 7])
            ->update(['merchant_id' => $merchant1->id]);

        // Assign products 8-14 to merchant2
        Product::whereIn('id', [8, 9, 10, 11, 12, 13, 14])
            ->update(['merchant_id' => $merchant2->id]);

        // Remaining products stay without merchant (owned by platform)
        // Products 15-22 have merchant_id = null
    }
}
