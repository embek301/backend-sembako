<?php

// app/Http/Controllers/Api/MerchantWithdrawalController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantWithdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MerchantWithdrawalController extends Controller
{
    /**
     * Get withdrawal history
     */
    public function index(Request $request)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant account not found'
            ], 404);
        }

        $withdrawals = MerchantWithdrawal::where('merchant_id', $merchant->id)
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $withdrawals
        ]);
    }

    /**
     * Get withdrawal detail
     */
    public function show(Request $request, $id)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant account not found'
            ], 404);
        }

        $withdrawal = MerchantWithdrawal::where('merchant_id', $merchant->id)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $withdrawal
        ]);
    }

    /**
     * Request withdrawal
     */
    public function store(Request $request)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant account not found'
            ], 404);
        }

        $request->validate([
            'amount' => 'required|numeric|min:10000', // Minimum 10k
            'notes' => 'nullable|string'
        ]);

        // Check if merchant has pending withdrawal
        $hasPending = MerchantWithdrawal::where('merchant_id', $merchant->id)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($hasPending) {
            return response()->json([
                'success' => false,
                'message' => 'You have a pending withdrawal request'
            ], 400);
        }

        // Check available balance
        $availableBalance = $merchant->available_balance;

        if ($request->amount > $availableBalance) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance. Available: Rp ' . number_format($availableBalance, 0, ',', '.')
            ], 400);
        }

        // Check bank account info
        if (!$merchant->bank_name || !$merchant->bank_account_number || !$merchant->bank_account_name) {
            return response()->json([
                'success' => false,
                'message' => 'Please complete your bank account information first'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $withdrawal = MerchantWithdrawal::create([
                'merchant_id' => $merchant->id,
                'amount' => $request->amount,
                'bank_name' => $merchant->bank_name,
                'bank_account_number' => $merchant->bank_account_number,
                'bank_account_name' => $merchant->bank_account_name,
                'status' => 'pending',
                'notes' => $request->notes
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal request submitted successfully',
                'data' => $withdrawal
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create withdrawal request'
            ], 500);
        }
    }

    /**
     * Cancel withdrawal (only if still pending)
     */
    public function cancel(Request $request, $id)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant account not found'
            ], 404);
        }

        $withdrawal = MerchantWithdrawal::where('merchant_id', $merchant->id)
            ->findOrFail($id);

        if ($withdrawal->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending withdrawals can be cancelled'
            ], 400);
        }

        $withdrawal->update([
            'status' => 'rejected',
            'rejection_reason' => 'Cancelled by merchant'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal request cancelled'
        ]);
    }

    /**
     * Get balance info
     */
    public function balance(Request $request)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant account not found'
            ], 404);
        }

        $data = [
            'available_balance' => $merchant->available_balance,
            'pending_balance' => $merchant->pending_balance,
            'total_revenue' => $merchant->total_revenue,
            'total_withdrawn' => $merchant->withdrawals()
                ->where('status', 'completed')
                ->sum('amount'),
            'pending_withdrawal' => $merchant->withdrawals()
                ->whereIn('status', ['pending', 'processing'])
                ->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }
}