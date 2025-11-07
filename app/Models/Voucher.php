<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Voucher extends Model
{
    protected $fillable = [
        'code', 'name', 'type', 'value', 'min_purchase', 
        'max_discount', 'usage_limit', 'used_count', 
        'start_date', 'end_date', 'is_active'
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_purchase' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_active' => 'boolean'
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('start_date', '<=', Carbon::now())
            ->where('end_date', '>=', Carbon::now())
            ->whereRaw('(usage_limit IS NULL OR used_count < usage_limit)');
    }

    public function calculateDiscount($subtotal)
    {
        if ($subtotal < $this->min_purchase) {
            return 0;
        }

        if ($this->type === 'percentage') {
            $discount = $subtotal * ($this->value / 100);
            return $this->max_discount ? min($discount, $this->max_discount) : $discount;
        }

        return $this->value;
    }
}