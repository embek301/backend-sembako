<?php

// app/Http/Controllers/Api/OrderController.php - UPDATED
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Cart;
use App\Models\Product;
use App\Models\UserAddress;
use App\Models\Voucher;
use App\Models\DeliveryTracking;
use App\Models\MerchantPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;

class OrderController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get user orders
     */
    public function index(Request $request)
    {
        $query = Order::with(['items.product.merchant', 'address'])
            ->where('user_id', $request->user()->id);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->latest()->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get order detail
     */
    public function show(Request $request, $id)
    {
        $order = Order::with([
            'items.product.merchant',
            'address',
            'payment',
            'tracking.histories'
        ])
        ->where('user_id', $request->user()->id)
        ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }

    /**
     * Create new order from cart
     */
    public function store(Request $request)
    {
        $request->validate([
            'address_id' => 'required|exists:user_addresses,id',
            'payment_method' => 'required|in:midtrans,cod',
            'voucher_code' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        // Get user cart
        $cartItems = Cart::with('product.merchant')
            ->where('user_id', $request->user()->id)
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is empty'
            ], 400);
        }

        // Verify address belongs to user
        $address = UserAddress::where('id', $request->address_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        DB::beginTransaction();
        try {
            // Calculate subtotal and validate stock
            $subtotal = 0;
            foreach ($cartItems as $item) {
                if ($item->product->stock < $item->quantity) {
                    throw new \Exception("Insufficient stock for {$item->product->name}");
                }
                $subtotal += $item->product->price * $item->quantity;
            }

            // Apply voucher if provided
            $discount = 0;
            if ($request->voucher_code) {
                $voucher = Voucher::where('code', $request->voucher_code)
                    ->active()
                    ->first();

                if (!$voucher) {
                    throw new \Exception('Invalid or expired voucher');
                }

                $discount = $voucher->calculateDiscount($subtotal);
                $voucher->increment('used_count');
            }

            // Calculate shipping cost
            $shippingCost = 10000;

            $totalPrice = $subtotal + $shippingCost - $discount;

            // Create order
            $order = Order::create([
                'user_id' => $request->user()->id,
                'address_id' => $request->address_id,
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'discount' => $discount,
                'total_price' => $totalPrice,
                'payment_method' => $request->payment_method,
                'notes' => $request->notes,
                'status' => 'pending',
                'payment_status' => 'unpaid'
            ]);

            // Create order items, reduce stock, and create merchant payments
            $merchantPayments = [];
            
            foreach ($cartItems as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name,
                    'price' => $item->product->price,
                    'quantity' => $item->quantity,
                    'subtotal' => $item->product->price * $item->quantity
                ]);

                // Reduce product stock
                $item->product->decrement('stock', $item->quantity);

                // Calculate merchant payment
                if ($item->product->merchant_id) {
                    $itemAmount = $item->product->price * $item->quantity;
                    $merchant = $item->product->merchant;
                    
                    if (!isset($merchantPayments[$merchant->id])) {
                        $merchantPayments[$merchant->id] = [
                            'merchant' => $merchant,
                            'order_amount' => 0
                        ];
                    }
                    
                    $merchantPayments[$merchant->id]['order_amount'] += $itemAmount;
                }
            }

            // Create merchant payment records
            foreach ($merchantPayments as $merchantId => $data) {
                $merchant = $data['merchant'];
                $orderAmount = $data['order_amount'];
                
                // Calculate commission
                $commissionAmount = $orderAmount * ($merchant->commission_rate / 100);
                $merchantAmount = $orderAmount - $commissionAmount;

                MerchantPayment::create([
                    'merchant_id' => $merchantId,
                    'order_id' => $order->id,
                    'order_amount' => $orderAmount,
                    'commission_amount' => $commissionAmount,
                    'merchant_amount' => $merchantAmount,
                    'status' => 'pending'
                ]);
            }

            // Clear cart
            Cart::where('user_id', $request->user()->id)->delete();

            // Create delivery tracking
            DeliveryTracking::create([
                'order_id' => $order->id,
                'status' => 'waiting_driver'
            ]);

            DB::commit();

            // Load relationships
            $order->load(['items.product.merchant', 'address']);

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => $order
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Cancel order
     */
    public function cancel(Request $request, $id)
    {
        $request->validate([
            'cancel_reason' => 'required|string'
        ]);

        $order = Order::where('user_id', $request->user()->id)
            ->findOrFail($id);

        // Only pending or unpaid orders can be cancelled
        if (!in_array($order->status, ['pending', 'paid'])) {
            return response()->json([
                'success' => false,
                'message' => 'Order cannot be cancelled'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Restore product stock
            foreach ($order->items as $item) {
                $item->product->increment('stock', $item->quantity);
            }

            // Cancel merchant payments
            MerchantPayment::where('order_id', $order->id)
                ->update(['status' => 'failed']);

            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => $request->cancel_reason
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order cancelled successfully',
                'data' => $order
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order'
            ], 500);
        }
    }

    /**
     * Update order status (when payment is completed)
     */
    public function updateStatus(Request $request, Order $order)
    {
        $order->update(['status' => $request->status]);

        // Update merchant payment status when order is paid
        if ($request->status === 'paid') {
            MerchantPayment::where('order_id', $order->id)
                ->update([
                    'status' => 'paid',
                    'paid_at' => now()
                ]);
        }

        // Send notification based on status
        $title = $this->getNotificationTitle($request->status);
        $body = $this->getNotificationBody($order, $request->status);

        $this->notificationService->sendToUser(
            $order->user,
            $title,
            $body,
            [
                'type' => 'order_status',
                'order_id' => $order->id,
                'status' => $request->status,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Order status updated',
        ]);
    }

    private function getNotificationTitle($status)
    {
        return match($status) {
            'paid' => '✅ Payment Confirmed',
            'processing' => '📦 Order Processing',
            'shipped' => '🚚 Order Shipped',
            'delivered' => '🎉 Order Delivered',
            'cancelled' => '❌ Order Cancelled',
            default => 'Order Update',
        };
    }

    private function getNotificationBody($order, $status)
    {
        return match($status) {
            'paid' => "Your order #{$order->order_number} payment has been confirmed!",
            'processing' => "Your order #{$order->order_number} is being processed",
            'shipped' => "Your order #{$order->order_number} has been shipped!",
            'delivered' => "Your order #{$order->order_number} has been delivered!",
            'cancelled' => "Your order #{$order->order_number} has been cancelled",
            default => "Your order #{$order->order_number} status has been updated",
        };
    }
}