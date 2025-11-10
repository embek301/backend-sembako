<?php
// app/Models/MerchantWithdrawal.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantWithdrawal extends Model
{
    protected $fillable = [
        'merchant_id',
        'amount',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'status',
        'notes',
        'reject_reason',
        'processed_at'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'processed_at' => 'datetime'
    ];

    public function merchant()
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }
}
