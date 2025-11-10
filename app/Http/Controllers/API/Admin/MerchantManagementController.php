<?php

// app/Http/Controllers/Api/Admin/MerchantManagementController.php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\MerchantWithdrawal;
use Illuminate\Http\Request;

class MerchantManagementController extends Controller
{
    /**
     * Get all merchants
     */
    public function index(Request $request)
    {
        $query = User::where('role', 'merchant');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by verification status
        if ($request->has('is_verified')) {
            $query->where('is_verified', $request->is_verified);
        }

        $merchants = $query->withCount('products')
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $merchants
        ]);
    }

    /**
     * Get merchant detail
     */
    public function show($id)
    {
        $merchant = User::where('role', 'merchant')
            ->with(['products', 'merchantPayments', 'withdrawals'])
            ->findOrFail($id);

        $stats = [
            'total_products' => $merchant->products()->count(),
            'active_products' => $merchant->products()->active()->count(),
            'total_sales' => $merchant->merchantPayments()->where('status', 'paid')->sum('order_amount'),
            'total_earnings' => $merchant->merchantPayments()->where('status', 'paid')->sum('merchant_amount'),
            'total_withdrawals' => $merchant->withdrawals()->where('status', 'completed')->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'merchant' => $merchant,
                'stats' => $stats
            ]
        ]);
    }

    /**
     * Verify merchant
     */
    public function verify(Request $request, $id)
    {
        $merchant = User::where('role', 'merchant')
            ->findOrFail($id);

        if ($merchant->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant is already verified'
            ], 400);
        }

        $merchant->update([
            'is_verified' => true,
            'verified_at' => now(),
            'status' => 'active'
        ]);

        // TODO: Send notification to merchant

        return response()->json([
            'success' => true,
            'message' => 'Merchant verified successfully',
            'data' => $merchant
        ]);
    }

    /**
     * Reject/Unverify merchant
     */
    public function unverify(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string'
        ]);

        $merchant = User::where('role', 'merchant')
            ->findOrFail($id);

        $merchant->update([
            'is_verified' => false,
            'verified_at' => null,
            'status' => 'inactive'
        ]);

        // TODO: Send notification to merchant with reason

        return response()->json([
            'success' => true,
            'message' => 'Merchant unverified successfully',
            'data' => $merchant
        ]);
    }

    /**
     * Update merchant commission rate
     */
    public function updateCommission(Request $request, $id)
    {
        $request->validate([
            'commission_rate' => 'required|numeric|min:0|max:100'
        ]);

        $merchant = User::where('role', 'merchant')
            ->findOrFail($id);

        $merchant->update([
            'commission_rate' => $request->commission_rate
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Commission rate updated successfully',
            'data' => $merchant
        ]);
    }

    /**
     * Get all withdrawal requests
     */
    public function withdrawals(Request $request)
    {
        $query = MerchantWithdrawal::with('merchant');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $withdrawals = $query->latest()->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $withdrawals
        ]);
    }

    /**
     * Process withdrawal request
     */
    public function processWithdrawal(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:processing,completed,rejected',
            'notes' => 'nullable|string',
            'reject_reason' => 'required_if:status,rejected|string'
        ]);

        $withdrawal = MerchantWithdrawal::findOrFail($id);

        if ($withdrawal->status !== 'pending' && $withdrawal->status !== 'processing') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or processing withdrawals can be updated'
            ], 400);
        }

        $data = [
            'status' => $request->status,
            'notes' => $request->notes
        ];

        if ($request->status === 'rejected') {
            $data['reject_reason'] = $request->reject_reason;
        }

        if ($request->status === 'completed' || $request->status === 'rejected') {
            $data['processed_at'] = now();
        }

        $withdrawal->update($data);

        // TODO: Send notification to merchant

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal request updated successfully',
            'data' => $withdrawal
        ]);
    }

    /**
     * Get merchant statistics
     */
    public function statistics()
    {
        $stats = [
            'total_merchants' => User::where('role', 'merchant')->count(),
            'verified_merchants' => User::where('role', 'merchant')->where('is_verified', true)->count(),
            'pending_verification' => User::where('role', 'merchant')->where('is_verified', false)->count(),
            'pending_withdrawals' => MerchantWithdrawal::where('status', 'pending')->count(),
            'pending_withdrawal_amount' => MerchantWithdrawal::where('status', 'pending')->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}