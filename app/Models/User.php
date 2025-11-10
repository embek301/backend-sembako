<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;  

    protected $fillable = [
        'name', 
        'email', 
        'phone', 
        'address', 
        'avatar', 
        'role', 
        'status', 
        'password', 
        'fcm_token',
        // Merchant fields
        'store_name',
        'store_description',
        'store_logo',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'commission_rate',
        'is_verified',
        'verified_at',
    ];

    protected $hidden = [
        'password', 
        'remember_token'
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'commission_rate' => 'decimal:2',
    ];

    // User relationships
    public function addresses()
    {
        return $this->hasMany(UserAddress::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function cart()
    {
        return $this->hasMany(Cart::class);
    }

    public function wishlist()
    {
        return $this->hasMany(Wishlist::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    // Merchant relationships
    public function products()
    {
        return $this->hasMany(Product::class, 'merchant_id');
    }

    public function merchantPayments()
    {
        return $this->hasMany(MerchantPayment::class, 'merchant_id');
    }

    public function withdrawals()
    {
        return $this->hasMany(MerchantWithdrawal::class, 'merchant_id');
    }

    // Helper methods
    public function isMerchant()
    {
        return $this->role === 'merchant';
    }

    public function isVerifiedMerchant()
    {
        return $this->role === 'merchant' && $this->is_verified == 1;
    }

    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    public function isDriver()
    {
        return $this->role === 'driver';
    }
}