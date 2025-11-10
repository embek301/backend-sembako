<?php

// app/Models/MerchantPayment.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantPayment extends Model
{
    protected $fillable = [
        'merchant_id',
        'order_id',
        'order_amount',
        'commission_amount',
        'merchant_amount',
        'status',
        'paid_at',
        'notes'
    ];

    protected $casts = [
        'order_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'merchant_amount' => 'decimal:2',
        'paid_at' => 'datetime'
    ];

    public function merchant()
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}