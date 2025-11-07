<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryTracking extends Model
{
    protected $fillable = [
        'order_id', 'driver_id', 'driver_name', 'driver_phone',
        'vehicle_number', 'status', 'latitude', 'longitude',
        'estimated_delivery', 'notes'
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'estimated_delivery' => 'datetime'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function histories()
    {
        return $this->hasMany(TrackingHistory::class);
    }
}