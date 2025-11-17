<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryTracking extends Model
{
    protected $fillable = [
        'order_id', 
        'driver_id', 
        'driver_name', 
        'driver_phone',
        'vehicle_number', 
        'status', 
        'latitude', 
        'longitude',
        'estimated_delivery', 
        'notes'
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'estimated_delivery' => 'datetime'
    ];

    /**
     * Valid tracking statuses
     */
    const STATUS_PENDING_PAYMENT = 'pending_payment';
    const STATUS_WAITING_MERCHANT = 'waiting_merchant';
    const STATUS_WAITING_DRIVER = 'waiting_driver';
    const STATUS_DRIVER_ASSIGNED = 'driver_assigned';
    const STATUS_ON_THE_WAY = 'on_the_way';
    const STATUS_ARRIVED = 'arrived';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Get all valid statuses
     */
    public static function getValidStatuses()
    {
        return [
            self::STATUS_PENDING_PAYMENT,
            self::STATUS_WAITING_MERCHANT,
            self::STATUS_WAITING_DRIVER,
            self::STATUS_DRIVER_ASSIGNED,
            self::STATUS_ON_THE_WAY,
            self::STATUS_ARRIVED,
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED,
        ];
    }

    /**
     * Relationships
     */
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
        return $this->hasMany(TrackingHistory::class, 'delivery_tracking_id');
    }

    /**
     * Scopes
     */
    public function scopeWaitingDriver($query)
    {
        return $query->where('status', self::STATUS_WAITING_DRIVER);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_DELIVERED, 
            self::STATUS_CANCELLED
        ]);
    }

    public function scopeInProgress($query)
    {
        return $query->whereIn('status', [
            self::STATUS_DRIVER_ASSIGNED,
            self::STATUS_ON_THE_WAY,
            self::STATUS_ARRIVED
        ]);
    }

    /**
     * Helper methods
     */
    public function isWaitingDriver()
    {
        return $this->status === self::STATUS_WAITING_DRIVER;
    }

    public function hasDriver()
    {
        return !is_null($this->driver_id);
    }

    public function isDelivered()
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function isCancelled()
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isActive()
    {
        return !in_array($this->status, [
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED
        ]);
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute()
    {
        $labels = [
            self::STATUS_PENDING_PAYMENT => 'Waiting Payment',
            self::STATUS_WAITING_MERCHANT => 'Waiting Merchant Approval',
            self::STATUS_WAITING_DRIVER => 'Looking for Driver',
            self::STATUS_DRIVER_ASSIGNED => 'Driver Assigned',
            self::STATUS_ON_THE_WAY => 'On the Way',
            self::STATUS_ARRIVED => 'Driver Arrived',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_CANCELLED => 'Cancelled',
        ];

        return $labels[$this->status] ?? 'Unknown';
    }

    /**
     * Get progress percentage
     */
    public function getProgressPercentageAttribute()
    {
        $progress = [
            self::STATUS_PENDING_PAYMENT => 10,
            self::STATUS_WAITING_MERCHANT => 30,
            self::STATUS_WAITING_DRIVER => 50,
            self::STATUS_DRIVER_ASSIGNED => 60,
            self::STATUS_ON_THE_WAY => 80,
            self::STATUS_ARRIVED => 90,
            self::STATUS_DELIVERED => 100,
            self::STATUS_CANCELLED => 0,
        ];

        return $progress[$this->status] ?? 0;
    }
}