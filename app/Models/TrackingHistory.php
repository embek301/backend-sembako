<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingHistory extends Model
{
    public $timestamps = false;
    
    protected $fillable = [
        'delivery_tracking_id', 'status', 'description',
        'latitude', 'longitude', 'created_at'
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'created_at' => 'datetime'
    ];

    public function tracking()
    {
        return $this->belongsTo(DeliveryTracking::class, 'delivery_tracking_id');
    }
}