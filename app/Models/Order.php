<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'user_id',
        'address_id',
        'subtotal',
        'shipping_cost',
        'discount',
        'total_price',
        'status',
        'payment_status',
        'merchant_status', // ✅ ADD THIS
        'payment_method',
        'notes',
        'paid_at',
        'cancelled_at',
        'cancel_reason'
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'discount' => 'decimal:2',
        'total_price' => 'decimal:2',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            $order->order_number = 'ORD-' . strtoupper(Str::random(10));
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function address()
    {
        return $this->belongsTo(UserAddress::class, 'address_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    public function tracking()
    {
        return $this->hasOne(DeliveryTracking::class);
    }

    /**
     * ✅ Get all merchants from this order
     */
    public function merchants()
    {
        return $this->belongsToMany(
            User::class,
            'order_items',
            'order_id',
            'merchant_id'
        )->distinct();
    }
}