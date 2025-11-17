<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Cart;
use App\Models\DeliveryTracking;
use App\Models\TrackingHistory;
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
     * Get user orders with detailed status
     */
    public function index(Request $request)
    {
        $query = Order::with([
            'items.product.merchant',
            'address',
            'tracking' => function ($q) {
                $q->with('driver');
            }
        ])
            ->where('user_id', $request->user()->id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->latest()->paginate(15);

        // Add readable status for each order
        $orders->getCollection()->transform(function ($order) {
            $order->status_info = $this->getOrderStatusInfo($order);
            return $order;
        });

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get detailed order info
     */
    public function show(Request $request, $id)
    {
        $order = Order::with([
            'items.product.merchant',
            'address',
            'payment',
            'tracking.driver',
            'tracking.histories' => function ($q) {
                $q->orderBy('created_at', 'desc');
            }
        ])
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        // Add status info
        $order->status_info = $this->getOrderStatusInfo($order);
        $order->timeline = $this->generateDetailedTimeline($order);

        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }

    /**
     * Create new order
     */
    public function store(Request $request)
    {
        $request->validate([
            'address_id' => 'required|exists:user_addresses,id',
            'payment_method' => 'required|in:midtrans,cod',
            'voucher_code' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        $cartItems = Cart::with('product.merchant')
            ->where('user_id', $request->user()->id)
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is empty'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Calculate totals
            $subtotal = 0;
            foreach ($cartItems as $item) {
                if ($item->product->stock < $item->quantity) {
                    throw new \Exception("Insufficient stock for {$item->product->name}");
                }
                $subtotal += $item->product->price * $item->quantity;
            }

            // Apply voucher
            $discount = 0;
            if ($request->voucher_code) {
                $voucher = \App\Models\Voucher::where('code', $request->voucher_code)
                    ->active()
                    ->first();
                if ($voucher) {
                    $discount = $voucher->calculateDiscount($subtotal);
                    $voucher->increment('used_count');
                }
            }

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
                'payment_status' => 'unpaid',
                'merchant_status' => 'pending'
            ]);

            // Create order items
            $merchantPayments = [];
            foreach ($cartItems as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'merchant_id' => $item->product->merchant_id,
                    'product_name' => $item->product->name,
                    'price' => $item->product->price,
                    'quantity' => $item->quantity,
                    'subtotal' => $item->product->price * $item->quantity
                ]);

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

            // Create delivery tracking
            $tracking = DeliveryTracking::create([
                'order_id' => $order->id,
                'status' => 'pending_payment'
            ]);

            // Add initial tracking history
            TrackingHistory::create([
                'delivery_tracking_id' => $tracking->id,
                'status' => 'pending_payment',
                'description' => 'Order created, waiting for payment',
                'created_at' => now()
            ]);

            // Clear cart
            Cart::where('user_id', $request->user()->id)->delete();

            DB::commit();

            $order->load(['items.product.merchant', 'address', 'tracking']);

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => $order
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Order creation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Update payment status (called after successful payment)
     */
    public function updatePaymentStatus(Request $request, $id)
    {
        $order = Order::with(['tracking', 'items.merchant', 'user'])->findOrFail($id);

        if ($order->payment_status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Order already paid'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Update order
            $order->update([
                'payment_status' => 'paid',
                'status' => 'paid',
                'paid_at' => now()
            ]);

            // Update tracking - check if tracking exists first
            if ($order->tracking) {
                $order->tracking->update([
                    'status' => 'waiting_merchant'
                ]);

                TrackingHistory::create([
                    'delivery_tracking_id' => $order->tracking->id,
                    'status' => 'waiting_merchant',
                    'description' => 'Payment confirmed, waiting for merchant approval',
                    'created_at' => now()
                ]);
            } else {
                // Create tracking if doesn't exist
                $tracking = DeliveryTracking::create([
                    'order_id' => $order->id,
                    'status' => 'waiting_merchant'
                ]);

                TrackingHistory::create([
                    'delivery_tracking_id' => $tracking->id,
                    'status' => 'waiting_merchant',
                    'description' => 'Payment confirmed, waiting for merchant approval',
                    'created_at' => now()
                ]);
            }

            // Notify merchant with error handling & prevent duplicates
            try {
                $notifiedMerchants = [];
                foreach ($order->items as $item) {
                    $merchantId = $item->merchant_id;

                    // Avoid duplicate notifications
                    if ($merchantId && !in_array($merchantId, $notifiedMerchants)) {
                        $merchant = $item->merchant;
                        if ($merchant && $merchant->fcm_token) {
                            $this->notificationService->sendToUser(
                                $merchant,
                                '🔔 New Order',
                                "You have a new order #{$order->order_number}. Total: Rp " . number_format($order->total_price, 0, ',', '.'),
                                ['type' => 'new_order', 'order_id' => $order->id]
                            );
                            $notifiedMerchants[] = $merchantId;
                        }
                    }
                }

                if (!empty($notifiedMerchants)) {
                    \Log::info("Notified merchants: " . implode(', ', $notifiedMerchants) . " for order #{$order->order_number}");
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to notify merchant: ' . $e->getMessage());
            }

            // Notify customer with error handling
            try {
                if ($order->user && $order->user->fcm_token) {
                    $this->notificationService->sendToUser(
                        $order->user,
                        '✅ Payment Confirmed',
                        "Your payment for order #{$order->order_number} has been confirmed. Waiting for merchant approval.",
                        ['type' => 'order_status', 'order_id' => $order->id]
                    );
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to notify customer: ' . $e->getMessage());
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Payment status update failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment status'
            ], 500);
        }
    }

    /**
     * MERCHANT: Approve order
     */
    public function merchantApprove(Request $request, $id)
    {
        try {
            $merchant = $request->user();

            if (!$merchant || !$merchant->isMerchant()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized - Only merchants can approve orders'
                ], 403);
            }

            $order = Order::with(['items.product', 'user', 'tracking'])
                ->whereHas('items', function ($query) use ($merchant) {
                    $query->where('merchant_id', $merchant->id);
                })
                // ✅ Verify order is paid
                ->where('payment_status', 'paid')
                ->find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found, not paid yet, or does not belong to your store'
                ], 404);
            }

            // ✅ Double check payment status
            if ($order->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot approve unpaid order. Please wait for customer payment.'
                ], 400);
            }

            if ($order->merchant_status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order has already been ' . $order->merchant_status
                ], 400);
            }

            DB::beginTransaction();
            try {
                // Update order
                $order->update([
                    'merchant_status' => 'approved',
                    'status' => 'processing'
                ]);

                // Update merchant payment
                $merchantPayment = MerchantPayment::where('order_id', $order->id)
                    ->where('merchant_id', $merchant->id)
                    ->first();

                if ($merchantPayment) {
                    $merchantPayment->update([
                        'status' => 'paid',
                        'paid_at' => now()
                    ]);
                }

                // Update tracking
                if ($order->tracking) {
                    $order->tracking->update(['status' => 'waiting_driver']);

                    TrackingHistory::create([
                        'delivery_tracking_id' => $order->tracking->id,
                        'status' => 'waiting_driver',
                        'description' => 'Order approved by merchant, looking for driver',
                        'created_at' => now()
                    ]);
                } else {
                    $tracking = DeliveryTracking::create([
                        'order_id' => $order->id,
                        'status' => 'waiting_driver'
                    ]);

                    TrackingHistory::create([
                        'delivery_tracking_id' => $tracking->id,
                        'status' => 'waiting_driver',
                        'description' => 'Order approved by merchant, looking for driver',
                        'created_at' => now()
                    ]);
                }

                DB::commit();

                \Log::info("Order #{$order->order_number} approved by merchant #{$merchant->id}");

                // Notify customer
                try {
                    if ($order->user && $order->user->fcm_token) {
                        $this->notificationService->sendToUser(
                            $order->user,
                            '✅ Order Approved',
                            "Your order #{$order->order_number} has been approved and is being prepared for delivery",
                            ['type' => 'order_status', 'order_id' => $order->id]
                        );
                    }
                } catch (\Exception $e) {
                    \Log::warning('Failed to notify customer: ' . $e->getMessage());
                }

                // Notify drivers
                try {
                    $this->notifyAvailableDrivers($order);
                } catch (\Exception $e) {
                    \Log::warning('Failed to notify drivers: ' . $e->getMessage());
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Order approved successfully',
                    'data' => $order->fresh(['items', 'user', 'tracking'])
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            \Log::error('Error approving order', [
                'order_id' => $id,
                'merchant_id' => $request->user()->id ?? null,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * MERCHANT: Reject order
     */
    public function merchantReject(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|min:10'
        ]);

        try {
            $merchant = $request->user();

            if (!$merchant || !$merchant->isMerchant()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $order = Order::with(['items.product', 'user', 'tracking'])
                ->whereHas('items', function ($query) use ($merchant) {
                    $query->where('merchant_id', $merchant->id);
                })
                // ✅ Verify order is paid
                ->where('payment_status', 'paid')
                ->find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found or not paid yet'
                ], 404);
            }

            // ✅ Double check payment status
            if ($order->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot reject unpaid order.'
                ], 400);
            }

            if ($order->merchant_status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order already processed'
                ], 400);
            }

            DB::beginTransaction();
            try {
                // Restore stock
                foreach ($order->items as $item) {
                    if ($item->product) {
                        $item->product->increment('stock', $item->quantity);
                    }
                }

                // Update order
                $order->update([
                    'merchant_status' => 'rejected',
                    'status' => 'cancelled',
                    'cancel_reason' => $request->reason
                ]);

                // Cancel merchant payments
                MerchantPayment::where('order_id', $order->id)
                    ->where('merchant_id', $merchant->id)
                    ->update(['status' => 'failed']);

                // Update tracking
                if ($order->tracking) {
                    $order->tracking->update(['status' => 'cancelled']);

                    TrackingHistory::create([
                        'delivery_tracking_id' => $order->tracking->id,
                        'status' => 'cancelled',
                        'description' => "Order rejected by merchant: {$request->reason}",
                        'created_at' => now()
                    ]);
                }

                DB::commit();

                \Log::info("Order #{$order->order_number} rejected by merchant #{$merchant->id}. Reason: {$request->reason}");

                // Notify customer with refund info
                try {
                    if ($order->user && $order->user->fcm_token) {
                        $this->notificationService->sendToUser(
                            $order->user,
                            '❌ Order Rejected - Refund Initiated',
                            "Your order #{$order->order_number} was rejected. Reason: {$request->reason}. Refund will be processed within 3-5 business days.",
                            ['type' => 'order_status', 'order_id' => $order->id]
                        );
                    }
                } catch (\Exception $e) {
                    \Log::warning('Failed to notify customer: ' . $e->getMessage());
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Order rejected successfully. Customer will be refunded.',
                    'data' => $order->fresh(['items', 'user', 'tracking'])
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            \Log::error('Error rejecting order', [
                'order_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get merchant orders
     */
    public function merchantOrders(Request $request)
    {
        $merchant = $request->user();

        if (!$merchant || !$merchant->isMerchant()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $query = Order::with(['items.product', 'user', 'address', 'tracking'])
            ->whereHas('items', function ($q) use ($merchant) {
                $q->where('merchant_id', $merchant->id);
            })
            // ✅ CRITICAL FIX: Only show PAID orders
            ->where('payment_status', 'paid');

        // Filter by merchant_status if provided
        if ($request->has('status')) {
            $query->where('merchant_status', $request->status);
        }

        $orders = $query->latest()->paginate(15);

        // Add payment confirmation info to each order
        $orders->getCollection()->transform(function ($order) {
            $order->is_paid = true; // All orders here are paid
            $order->payment_confirmed_at = $order->paid_at;
            return $order;
        });

        return response()->json([
            'success' => true,
            'data' => $orders,
            'meta' => [
                'only_paid_orders' => true,
                'message' => 'Showing only paid orders'
            ]
        ]);
    }

    /**
     * Helper: Get order status info
     */
    private function getOrderStatusInfo($order)
    {
        $tracking = $order->tracking;

        $statusMap = [
            'pending_payment' => [
                'label' => 'Waiting Payment',
                'description' => 'Please complete your payment',
                'icon' => 'time',
                'color' => '#FF9800',
                'progress' => 10
            ],
            'waiting_merchant' => [
                'label' => 'Waiting Merchant',
                'description' => 'Merchant is reviewing your order',
                'icon' => 'storefront',
                'color' => '#2196F3',
                'progress' => 30
            ],
            'waiting_driver' => [
                'label' => 'Looking for Driver',
                'description' => 'Finding a driver for your order',
                'icon' => 'car',
                'color' => '#9C27B0',
                'progress' => 50
            ],
            'driver_assigned' => [
                'label' => 'Driver Assigned',
                'description' => 'Driver is preparing to pick up your order',
                'icon' => 'person',
                'color' => '#2196F3',
                'progress' => 60
            ],
            'on_the_way' => [
                'label' => 'On the Way',
                'description' => 'Driver is delivering your order',
                'icon' => 'bicycle',
                'color' => '#FF5722',
                'progress' => 80
            ],
            'arrived' => [
                'label' => 'Driver Arrived',
                'description' => 'Driver has arrived at your location',
                'icon' => 'location',
                'color' => '#F44336',
                'progress' => 90
            ],
            'delivered' => [
                'label' => 'Delivered',
                'description' => 'Order has been delivered',
                'icon' => 'checkmark-circle',
                'color' => '#4CAF50',
                'progress' => 100
            ],
            'cancelled' => [
                'label' => 'Cancelled',
                'description' => $order->cancel_reason ?? 'Order was cancelled',
                'icon' => 'close-circle',
                'color' => '#9E9E9E',
                'progress' => 0
            ]
        ];

        $status = $tracking ? $tracking->status : 'pending_payment';

        return $statusMap[$status] ?? [
            'label' => 'Unknown',
            'description' => 'Status not available',
            'icon' => 'help-circle',
            'color' => '#9E9E9E',
            'progress' => 0
        ];
    }

    /**
     * Generate detailed timeline
     */
    private function generateDetailedTimeline($order)
    {
        $timeline = [];

        // Order created
        $timeline[] = [
            'title' => 'Order Created',
            'description' => "Order #{$order->order_number} created",
            'timestamp' => $order->created_at,
            'status' => 'completed',
            'icon' => 'receipt'
        ];

        // Payment
        if ($order->payment_status === 'paid') {
            $timeline[] = [
                'title' => 'Payment Confirmed',
                'description' => 'Payment received',
                'timestamp' => $order->paid_at,
                'status' => 'completed',
                'icon' => 'card'
            ];
        } else {
            $timeline[] = [
                'title' => 'Waiting Payment',
                'description' => 'Please complete payment',
                'timestamp' => null,
                'status' => 'pending',
                'icon' => 'time'
            ];
        }

        // Merchant status
        if ($order->merchant_status === 'approved') {
            $timeline[] = [
                'title' => 'Merchant Approved',
                'description' => 'Order approved and being prepared',
                'timestamp' => $order->updated_at,
                'status' => 'completed',
                'icon' => 'storefront'
            ];
        } elseif ($order->merchant_status === 'rejected') {
            $timeline[] = [
                'title' => 'Order Rejected',
                'description' => $order->cancel_reason,
                'timestamp' => $order->updated_at,
                'status' => 'failed',
                'icon' => 'close-circle'
            ];
            return $timeline;
        } else {
            $timeline[] = [
                'title' => 'Merchant Review',
                'description' => 'Waiting for merchant approval',
                'timestamp' => null,
                'status' => 'pending',
                'icon' => 'storefront'
            ];
        }

        // Tracking histories
        if ($order->tracking && $order->tracking->histories) {
            foreach ($order->tracking->histories as $history) {
                $timeline[] = [
                    'title' => $this->getStatusTitle($history->status),
                    'description' => $history->description,
                    'timestamp' => $history->created_at,
                    'status' => 'completed',
                    'icon' => $this->getStatusIcon($history->status)
                ];
            }
        }

        return $timeline;
    }

    private function getStatusTitle($status)
    {
        $titles = [
            'waiting_driver' => 'Looking for Driver',
            'driver_assigned' => 'Driver Assigned',
            'on_the_way' => 'On the Way',
            'arrived' => 'Driver Arrived',
            'delivered' => 'Delivered',
        ];
        return $titles[$status] ?? ucwords(str_replace('_', ' ', $status));
    }

    private function getStatusIcon($status)
    {
        $icons = [
            'waiting_driver' => 'car',
            'driver_assigned' => 'person',
            'on_the_way' => 'bicycle',
            'arrived' => 'location',
            'delivered' => 'checkmark-done',
        ];
        return $icons[$status] ?? 'information-circle';
    }

    /**
     * Notify available drivers
     */
    private function notifyAvailableDrivers($order)
    {
        try {
            // Get all active drivers with FCM token
            $drivers = \App\Models\User::where('role', 'driver')
                ->where('status', 'active')
                ->whereNotNull('fcm_token')
                ->get();

            if ($drivers->isEmpty()) {
                \Log::info('No active drivers with FCM token found for notification');
                return;
            }

            $notifiedCount = 0;
            foreach ($drivers as $driver) {
                try {
                    $this->notificationService->sendToUser(
                        $driver,
                        '📦 New Delivery Available',
                        "Order #{$order->order_number} is available for pickup. Total: Rp " . number_format($order->total_price, 0, ',', '.'),
                        ['type' => 'new_delivery', 'order_id' => $order->id]
                    );
                    $notifiedCount++;
                } catch (\Exception $e) {
                    \Log::warning("Failed to notify driver {$driver->id}: " . $e->getMessage());
                    // Continue to next driver
                }
            }

            \Log::info("Notified {$notifiedCount}/{$drivers->count()} drivers about order #{$order->order_number}");
        } catch (\Exception $e) {
            \Log::error('Error in notifyAvailableDrivers: ' . $e->getMessage());
        }
    }

    /**
     * Cancel order (customer)
     */
    public function cancel(Request $request, $id)
    {
        $request->validate([
            'cancel_reason' => 'required|string|min:5'
        ]);

        $order = Order::where('user_id', $request->user()->id)
            ->with(['items.product', 'tracking'])
            ->findOrFail($id);

        // Only allow cancel if not yet delivered
        if (in_array($order->status, ['delivered', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Order cannot be cancelled'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Restore stock
            foreach ($order->items as $item) {
                if ($item->product) {
                    $item->product->increment('stock', $item->quantity);
                }
            }

            // Cancel merchant payments
            MerchantPayment::where('order_id', $order->id)
                ->update(['status' => 'failed']);

            $order->update([
                'status' => 'cancelled',
                'merchant_status' => 'rejected',
                'cancelled_at' => now(),
                'cancel_reason' => $request->cancel_reason
            ]);

            // Update tracking - check if exists
            if ($order->tracking) {
                $order->tracking->update(['status' => 'cancelled']);

                TrackingHistory::create([
                    'delivery_tracking_id' => $order->tracking->id,
                    'status' => 'cancelled',
                    'description' => "Order cancelled by customer: {$request->cancel_reason}",
                    'created_at' => now()
                ]);
            }

            DB::commit();

            // Log cancellation
            \Log::info("Order #{$order->order_number} cancelled by customer #{$request->user()->id}. Reason: {$request->cancel_reason}");

            return response()->json([
                'success' => true,
                'message' => 'Order cancelled successfully',
                'data' => $order
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Order cancellation failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order'
            ], 500);
        }
    }
}