<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'merchant_id', // NEW
        'category_id',
        'name',
        'description',
        'price',
        'stock',
        'unit',
        'sku',
        'images',
        'is_active',
        'min_order'
    ];

    protected $casts = [
        'images' => 'array',
        'price' => 'decimal:2',
        'is_active' => 'boolean'
    ];

    protected $appends = ['average_rating', 'total_reviews'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function merchant()
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function getAverageRatingAttribute()
    {
        try {
            return $this->reviews()->avg('rating') ?? 0;
        } catch (\Exception $e) {
            \Log::error('Error calculating average rating: ' . $e->getMessage());
            return 0;
        }
    }

    public function getTotalReviewsAttribute()
    {
        try {
            return $this->reviews()->count();
        } catch (\Exception $e) {
            \Log::error('Error counting reviews: ' . $e->getMessage());
            return 0;
        }
    }
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    public function scopeByMerchant($query, $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeVerifiedMerchants($query)
    {
        return $query->whereHas('merchant', function ($q) {
            $q->where('is_verified', true);
        });
    }
}