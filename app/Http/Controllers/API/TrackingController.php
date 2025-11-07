<?php

// app/Http/Controllers/Api/TrackingController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\DeliveryTracking;
use App\Models\TrackingHistory;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    /**
     * Get order tracking info
     */
    public function show(Request $request, $orderId)
    {
        $order = Order::with(['tracking.driver', 'tracking.histories'])
            ->where('user_id', $request->user()->id)
            ->findOrFail($orderId);

        if (!$order->tracking) {
            return response()->json([
                'success' => false,
                'message' => 'Tracking information not available'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order' => [
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'created_at' => $order->created_at
                ],
                'tracking' => $order->tracking,
                'histories' => $order->tracking->histories
            ]
        ]);
    }

    /**
     * Get real-time location (for customer)
     */
    public function location(Request $request, $orderId)
    {
        $order = Order::where('user_id', $request->user()->id)
            ->findOrFail($orderId);

        $tracking = $order->tracking;

        if (!$tracking || !$tracking->latitude || !$tracking->longitude) {
            return response()->json([
                'success' => false,
                'message' => 'Location not available'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'latitude' => $tracking->latitude,
                'longitude' => $tracking->longitude,
                'status' => $tracking->status,
                'updated_at' => $tracking->updated_at
            ]
        ]);
    }

    /**
     * Update tracking location (for driver)
     */
    public function updateLocation(Request $request, $orderId)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'status' => 'sometimes|in:waiting_driver,driver_assigned,on_the_way,arrived,delivered',
            'notes' => 'nullable|string'
        ]);

        // Verify driver role
        if ($request->user()->role !== 'driver' && $request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $order = Order::findOrFail($orderId);
        $tracking = $order->tracking;

        if (!$tracking) {
            return response()->json([
                'success' => false,
                'message' => 'Tracking not found'
            ], 404);
        }

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

        // Create tracking history
        TrackingHistory::create([
            'delivery_tracking_id' => $tracking->id,
            'status' => $tracking->status,
            'description' => $request->notes ?? 'Location updated',
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'created_at' => now()
        ]);

        // Update order status based on tracking status
        if ($request->status === 'delivered') {
            $order->update(['status' => 'delivered']);
        } elseif ($request->status === 'on_the_way') {
            $order->update(['status' => 'shipped']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Location updated successfully',
            'data' => $tracking
        ]);
    }

    /**
     * Assign driver to order (for admin)
     */
    public function assignDriver(Request $request, $orderId)
    {
        $request->validate([
            'driver_id' => 'required|exists:users,id',
            'vehicle_number' => 'required|string',
            'estimated_delivery' => 'required|date'
        ]);

        // Verify admin role
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $order = Order::findOrFail($orderId);
        $tracking = $order->tracking;

        if (!$tracking) {
            return response()->json([
                'success' => false,
                'message' => 'Tracking not found'
            ], 404);
        }

        // Get driver info
        $driver = \App\Models\User::findOrFail($request->driver_id);

        $tracking->update([
            'driver_id' => $request->driver_id,
            'driver_name' => $driver->name,
            'driver_phone' => $driver->phone,
            'vehicle_number' => $request->vehicle_number,
            'status' => 'driver_assigned',
            'estimated_delivery' => $request->estimated_delivery
        ]);

        // Create tracking history
        TrackingHistory::create([
            'delivery_tracking_id' => $tracking->id,
            'status' => 'driver_assigned',
            'description' => "Driver {$driver->name} assigned to this order",
            'created_at' => now()
        ]);

        // Update order status
        $order->update(['status' => 'processing']);

        return response()->json([
            'success' => true,
            'message' => 'Driver assigned successfully',
            'data' => $tracking
        ]);
    }

    /**
     * Get all orders for driver
     */
    public function driverOrders(Request $request)
    {
        // Verify driver role
        if ($request->user()->role !== 'driver') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $trackings = DeliveryTracking::with('order.user', 'order.address', 'order.items')
            ->where('driver_id', $request->user()->id)
            ->whereIn('status', ['driver_assigned', 'on_the_way', 'arrived'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $trackings
        ]);
    }
}