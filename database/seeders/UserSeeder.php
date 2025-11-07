<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        // Admin
        User::create([
            'name' => 'Admin Koperasi',
            'email' => 'admin@sembako.com',
            'phone' => '081234567890',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'status' => 'active'
        ]);

        // Driver
        User::create([
            'name' => 'Driver 1',
            'email' => 'driver@sembako.com',
            'phone' => '081234567891',
            'password' => Hash::make('password'),
            'role' => 'driver',
            'status' => 'active'
        ]);

        // Member
        User::create([
            'name' => 'John Doe',
            'email' => 'member@sembako.com',
            'phone' => '081234567892',
            'address' => 'Jl. Contoh No. 123, Surabaya',
            'password' => Hash::make('password'),
            'role' => 'member',
            'status' => 'active'
        ]);

        // Additional test members
        User::create([
            'name' => 'Jane Smith',
            'email' => 'jane@sembako.com',
            'phone' => '081234567893',
            'address' => 'Jl. Merdeka No. 45, Surabaya',
            'password' => Hash::make('password'),
            'role' => 'member',
            'status' => 'active'
        ]);

        User::create([
            'name' => 'Bob Wilson',
            'email' => 'bob@sembako.com',
            'phone' => '081234567894',
            'address' => 'Jl. Pahlawan No. 78, Surabaya',
            'password' => Hash::make('password'),
            'role' => 'member',
            'status' => 'active'
        ]);
    }
}