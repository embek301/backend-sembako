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
        'verified_at'
    ];

    protected $hidden = [
        'password', 
        'remember_token',
        'bank_account_number' // Hide sensitive data
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'commission_rate' => 'decimal:2',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime'
    ];

    // Existing relationships
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

    public function isMerchant()
    {
        return $this->role === 'merchant';
    }

    public function isVerifiedMerchant()
    {
        return $this->role === 'merchant' && $this->is_verified && $this->status === 'active';
    }

    // ✅ Relationships
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

}