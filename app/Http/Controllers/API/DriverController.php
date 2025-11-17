<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\DeliveryTracking;
use App\Models\TrackingHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;

class DriverController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get available orders for driver
     */
    public function availableOrders(Request $request)
    {
        if (!$request->user()->isDriver()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $orders = Order::with(['items', 'address', 'user'])
            ->whereHas('tracking', function($query) {
                $query->where('status', 'waiting_driver');
            })
            ->where('merchant_status', 'approved')
            ->where('payment_status', 'paid')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get driver's active deliveries
     */
    public function myDeliveries(Request $request)
    {
        if (!$request->user()->isDriver()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $deliveries = DeliveryTracking::with(['order.items', 'order.address', 'order.user'])
            ->where('driver_id', $request->user()->id)
            ->whereIn('status', ['driver_assigned', 'on_the_way', 'arrived'])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $deliveries
        ]);
    }

    /**
     * Get driver's delivery history
     */
    public function deliveryHistory(Request $request)
    {
        if (!$request->user()->isDriver()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $query = DeliveryTracking::with(['order.items', 'order.address', 'order.user'])
            ->where('driver_id', $request->user()->id)
            ->whereIn('status', ['delivered', 'cancelled']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $history = $query->latest()->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }

    /**
     * DRIVER: Accept order
     */
    public function acceptOrder(Request $request, $orderId)
    {
        if (!$request->user()->isDriver()) {
            return response()->json([
                'success' => false,
                'message' => 'Only drivers can accept orders'
            ], 403);
        }

        $order = Order::with(['tracking', 'user', 'items'])->findOrFail($orderId);
        $tracking = $order->tracking;

        if (!$tracking) {
            return response()->json([
                'success' => false,
                'message' => 'Tracking not found'
            ], 404);
        }

        if ($tracking->status !== 'waiting_driver') {
            return response()->json([
                'success' => false,
                'message' => 'Order is not available for acceptance'
            ], 400);
        }

        // Check if driver already has too many active deliveries
        $activeDeliveries = DeliveryTracking::where('driver_id', $request->user()->id)
            ->whereIn('status', ['driver_assigned', 'on_the_way', 'arrived'])
            ->count();

        if ($activeDeliveries >= 5) { // Max 5 active deliveries
            return response()->json([
                'success' => false,
                'message' => 'You have reached maximum active deliveries (5)'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Update tracking
            $tracking->update([
                'driver_id' => $request->user()->id,
                'driver_name' => $request->user()->name,
                'driver_phone' => $request->user()->phone,
                'status' => 'driver_assigned',
                'assigned_at' => now()
            ]);

            // Create tracking history
            TrackingHistory::create([
                'delivery_tracking_id' => $tracking->id,
                'status' => 'driver_assigned',
                'description' => "Driver {$request->user()->name} accepted the order",
                'created_at' => now(),
            ]);

            // Update order status
            $order->update([
                'status' => 'processing'
            ]);

            // Notify customer
            $this->notificationService->sendToUser(
                $order->user,
                '✅ Driver Assigned',
                "Driver {$request->user()->name} has been assigned to deliver your order #{$order->order_number}",
                ['type' => 'order_status', 'order_id' => $order->id]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order accepted successfully',
                'data' => $tracking->fresh()->load('order')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to accept order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * DRIVER: Reject order (if driver can't take it)
     */
    public function rejectOrder(Request $request, $orderId)
    {
        $request->validate([
            'reason' => 'required|string'
        ]);

        if (!$request->user()->isDriver()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $order = Order::with(['tracking'])->findOrFail($orderId);
        $tracking = $order->tracking;

        if (!$tracking || $tracking->status !== 'waiting_driver') {
            return response()->json([
                'success' => false,
                'message' => 'Order is not available'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Create tracking history for reject
            TrackingHistory::create([
                'delivery_tracking_id' => $tracking->id,
                'status' => 'waiting_driver',
                'description' => "Driver {$request->user()->name} rejected: {$request->reason}",
                'created_at' => now(),
            ]);

            // Keep status as waiting_driver so other drivers can accept

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order rejected successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject order'
            ], 500);
        }
    }

    /**
     * DRIVER: Update location
     */
    public function updateLocation(Request $request, $orderId)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'status' => 'sometimes|in:driver_assigned,on_the_way,arrived,delivered',
            'notes' => 'nullable|string',
        ]);

        if (!$request->user()->isDriver()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $order = Order::with(['tracking', 'user'])->findOrFail($orderId);
        $tracking = $order->tracking;

        if (!$tracking) {
            return response()->json([
                'success' => false,
                'message' => 'Tracking not found'
            ], 404);
        }

        // Verify driver owns this delivery
        if ($tracking->driver_id != $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Not your delivery'
            ], 403);
        }

        DB::beginTransaction();
        try {
            $oldStatus = $tracking->status;

            // Update tracking
            $data = [
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
            ];

            if ($request->has('status')) {
                $data['status'] = $request->status;
            }

            if ($request->has('notes')) {
                $data['notes'] = $request->notes;
            }

            $tracking->update($data);

            // Create tracking history if status changed
            if ($request->has('status') && $request->status !== $oldStatus) {
                TrackingHistory::create([
                    'delivery_tracking_id' => $tracking->id,
                    'status' => $tracking->status,
                    'description' => $request->notes ?? $this->getStatusDescription($tracking->status),
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'created_at' => now(),
                ]);

                // Update order status and send notifications
                $this->handleStatusChange($order, $tracking->status);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Location updated successfully',
                'data' => $tracking->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update location: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * DRIVER: Start delivery (picked up from merchant)
     */
    public function startDelivery(Request $request, $orderId)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'photo' => 'nullable|image|max:2048', // Photo of picked up order
            'notes' => 'nullable|string'
        ]);

        if (!$request->user()->isDriver()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $order = Order::with(['tracking', 'user'])->findOrFail($orderId);
        $tracking = $order->tracking;

        if ($tracking->driver_id != $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Not your delivery'
            ], 403);
        }

        if ($tracking->status !== 'driver_assigned') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid status to start delivery'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Handle photo upload if provided
            $photoPath = null;
            if ($request->hasFile('photo')) {
                $photo = $request->file('photo');
                $filename = 'pickup_' . $orderId . '_' . time() . '.' . $photo->getClientOriginalExtension();
                $photoPath = $photo->storeAs('deliveries', $filename, 'public');
            }

            // Update tracking
            $tracking->update([
                'status' => 'on_the_way',
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'pickup_photo' => $photoPath,
                'picked_up_at' => now()
            ]);

            // Create history
            TrackingHistory::create([
                'delivery_tracking_id' => $tracking->id,
                'status' => 'on_the_way',
                'description' => $request->notes ?? 'Order picked up, on the way to customer',
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'created_at' => now(),
            ]);

            // Update order status
            $order->update(['status' => 'shipped']);

            // Notify customer
            $this->notificationService->sendToUser(
                $order->user,
                '🚚 On the Way',
                "Driver is on the way to deliver your order #{$order->order_number}",
                ['type' => 'order_status', 'order_id' => $order->id]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Delivery started successfully',
                'data' => $tracking->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to start delivery: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * DRIVER: Complete delivery
     */
    public function completeDelivery(Request $request, $orderId)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'photo' => 'required|image|max:2048', // Proof of delivery
            'notes' => 'nullable|string',
            'signature' => 'nullable|string' // Base64 signature
        ]);

        if (!$request->user()->isDriver()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $order = Order::with(['tracking', 'user'])->findOrFail($orderId);
        $tracking = $order->tracking;

        if ($tracking->driver_id != $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Not your delivery'
            ], 403);
        }

        if (!in_array($tracking->status, ['on_the_way', 'arrived'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid status to complete delivery'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Handle photo upload
            $photo = $request->file('photo');
            $filename = 'delivered_' . $orderId . '_' . time() . '.' . $photo->getClientOriginalExtension();
            $photoPath = $photo->storeAs('deliveries', $filename, 'public');

            // Handle signature if provided
            $signaturePath = null;
            if ($request->has('signature')) {
                // Save base64 signature
                $signatureData = $request->signature;
                $signatureFilename = 'signature_' . $orderId . '_' . time() . '.png';
                
                // Decode base64 and save
                $image = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $signatureData));
                $path = storage_path('app/public/deliveries/' . $signatureFilename);
                file_put_contents($path, $image);
                
                $signaturePath = 'deliveries/' . $signatureFilename;
            }

            // Update tracking
            $tracking->update([
                'status' => 'delivered',
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'delivery_photo' => $photoPath,
                'signature' => $signaturePath,
                'notes' => $request->notes,
                'delivered_at' => now()
            ]);

            // Create history
            TrackingHistory::create([
                'delivery_tracking_id' => $tracking->id,
                'status' => 'delivered',
                'description' => 'Order delivered successfully',
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'created_at' => now(),
            ]);

            // Update order status
            $order->update([
                'status' => 'delivered',
                'delivered_at' => now()
            ]);

            // Notify customer
            $this->notificationService->sendToUser(
                $order->user,
                '🎉 Order Delivered',
                "Your order #{$order->order_number} has been delivered successfully!",
                ['type' => 'order_status', 'order_id' => $order->id]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Delivery completed successfully',
                'data' => $tracking->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete delivery: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper: Handle status change notifications
     */
    private function handleStatusChange($order, $status)
    {
        switch ($status) {
            case 'on_the_way':
                $order->update(['status' => 'shipped']);
                $this->notificationService->sendToUser(
                    $order->user,
                    '🚚 On the Way',
                    "Driver is on the way to deliver order #{$order->order_number}",
                    ['type' => 'order_status', 'order_id' => $order->id]
                );
                break;

            case 'arrived':
                $this->notificationService->sendToUser(
                    $order->user,
                    '📍 Driver Arrived',
                    "Driver has arrived at your location for order #{$order->order_number}",
                    ['type' => 'order_status', 'order_id' => $order->id]
                );
                break;

            case 'delivered':
                $order->update([
                    'status' => 'delivered',
                    'delivered_at' => now()
                ]);
                $this->notificationService->sendToUser(
                    $order->user,
                    '🎉 Order Delivered',
                    "Your order #{$order->order_number} has been delivered!",
                    ['type' => 'order_status', 'order_id' => $order->id]
                );
                break;
        }
    }

    /**
     * Helper: Get status description
     */
    private function getStatusDescription($status)
    {
        $descriptions = [
            'driver_assigned' => 'Driver assigned to your order',
            'on_the_way' => 'Driver is on the way',
            'arrived' => 'Driver has arrived at your location',
            'delivered' => 'Order delivered successfully'
        ];

        return $descriptions[$status] ?? 'Status updated';
    }

    /**
     * Get driver statistics
     */
    public function statistics(Request $request)
    {
        if (!$request->user()->isDriver()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $driverId = $request->user()->id;

        $stats = [
            'total_deliveries' => DeliveryTracking::where('driver_id', $driverId)
                ->where('status', 'delivered')
                ->count(),
                
            'active_deliveries' => DeliveryTracking::where('driver_id', $driverId)
                ->whereIn('status', ['driver_assigned', 'on_the_way', 'arrived'])
                ->count(),
                
            'today_deliveries' => DeliveryTracking::where('driver_id', $driverId)
                ->where('status', 'delivered')
                ->whereDate('delivered_at', today())
                ->count(),
                
            'total_earnings' => 0, // You can calculate based on delivery fees
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}