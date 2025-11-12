<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\DeliveryTracking;
use App\Models\TrackingHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Services\NotificationService;

class OrderTrackingController extends Controller
{
    protected $notificationService;
    protected $locationIqToken = 'pk.4d123cf0387e433c81abebf68fc070ad';

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get order tracking info with full details
     */
    public function show(Request $request, $orderId)
    {
        $order = Order::with([
            'items.product',
            'address',
            'tracking.driver',
            'tracking.histories' => function($query) {
                $query->orderBy('created_at', 'desc');
            }
        ])
        ->where('user_id', $request->user()->id)
        ->findOrFail($orderId);

        // Calculate progress percentage
        $progress = $this->calculateProgress($order);

        // Get driver location if available
        $driverLocation = null;
        if ($order->tracking && $order->tracking->latitude && $order->tracking->longitude) {
            $driverLocation = [
                'latitude' => $order->tracking->latitude,
                'longitude' => $order->tracking->longitude,
                'updated_at' => $order->tracking->updated_at,
            ];
        }

        // Get delivery address coordinates
        $deliveryLocation = null;
        if ($order->address) {
            $deliveryLocation = [
                'latitude' => $order->address->latitude,
                'longitude' => $order->address->longitude,
                'address' => $order->address->address,
                'city' => $order->address->city,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'merchant_status' => $order->merchant_status,
                    'payment_status' => $order->payment_status,
                    'created_at' => $order->created_at,
                    'total_price' => $order->total_price,
                ],
                'progress' => $progress,
                'tracking' => $order->tracking,
                'driver_location' => $driverLocation,
                'delivery_location' => $deliveryLocation,
                'histories' => $order->tracking ? $order->tracking->histories : [],
                'timeline' => $this->generateTimeline($order),
            ]
        ]);
    }

    /**
     * Calculate order progress percentage
     */
    private function calculateProgress($order)
    {
        $steps = [
            'pending' => 0,
            'paid' => 20,
            'processing' => 40,
            'waiting_driver' => 50,
            'driver_assigned' => 60,
            'on_the_way' => 80,
            'arrived' => 90,
            'delivered' => 100,
        ];

        $status = $order->tracking ? $order->tracking->status : 'pending';
        return $steps[$status] ?? 0;
    }

    /**
     * Generate order timeline
     */
    private function generateTimeline($order)
    {
        $timeline = [];

        // Order created
        $timeline[] = [
            'title' => 'Order Created',
            'description' => "Order #{$order->order_number} created",
            'timestamp' => $order->created_at,
            'status' => 'completed',
            'icon' => 'checkmark-circle',
        ];

        // Payment
        if ($order->payment_status === 'paid') {
            $timeline[] = [
                'title' => 'Payment Confirmed',
                'description' => 'Payment received and confirmed',
                'timestamp' => $order->paid_at,
                'status' => 'completed',
                'icon' => 'card',
            ];
        }

        // Merchant approval
        if ($order->merchant_status === 'approved') {
            $timeline[] = [
                'title' => 'Merchant Approved',
                'description' => 'Order approved by merchant',
                'timestamp' => $order->updated_at,
                'status' => 'completed',
                'icon' => 'storefront',
            ];
        } elseif ($order->merchant_status === 'rejected') {
            $timeline[] = [
                'title' => 'Order Rejected',
                'description' => $order->cancel_reason ?? 'Rejected by merchant',
                'timestamp' => $order->updated_at,
                'status' => 'failed',
                'icon' => 'close-circle',
            ];
            return $timeline;
        }

        // Tracking histories
        if ($order->tracking && $order->tracking->histories) {
            foreach ($order->tracking->histories as $history) {
                $timeline[] = [
                    'title' => $this->getStatusTitle($history->status),
                    'description' => $history->description,
                    'timestamp' => $history->created_at,
                    'status' => 'completed',
                    'icon' => $this->getStatusIcon($history->status),
                ];
            }
        }

        return $timeline;
    }

    private function getStatusTitle($status)
    {
        $titles = [
            'waiting_driver' => 'Waiting for Driver',
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
            'waiting_driver' => 'time',
            'driver_assigned' => 'person',
            'on_the_way' => 'car',
            'arrived' => 'location',
            'delivered' => 'checkmark-done',
        ];
        return $icons[$status] ?? 'information-circle';
    }

    /**
     * Get driver real-time location
     */
    public function getDriverLocation(Request $request, $orderId)
    {
        $order = Order::where('user_id', $request->user()->id)
            ->findOrFail($orderId);

        $tracking = $order->tracking;

        if (!$tracking || !$tracking->latitude || !$tracking->longitude) {
            return response()->json([
                'success' => false,
                'message' => 'Driver location not available'
            ], 404);
        }

        // Calculate distance to destination using LocationIQ
        $distance = null;
        $eta = null;

        if ($order->address && $order->address->latitude && $order->address->longitude) {
            $route = $this->calculateRoute(
                $tracking->latitude,
                $tracking->longitude,
                $order->address->latitude,
                $order->address->longitude
            );

            if ($route) {
                $distance = $route['distance']; // in meters
                $eta = $route['duration']; // in seconds
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'driver' => [
                    'name' => $tracking->driver_name,
                    'phone' => $tracking->driver_phone,
                    'vehicle_number' => $tracking->vehicle_number,
                ],
                'location' => [
                    'latitude' => $tracking->latitude,
                    'longitude' => $tracking->longitude,
                    'updated_at' => $tracking->updated_at,
                ],
                'delivery' => [
                    'latitude' => $order->address->latitude,
                    'longitude' => $order->address->longitude,
                    'address' => $order->address->address,
                ],
                'distance_meters' => $distance,
                'eta_seconds' => $eta,
                'eta_minutes' => $eta ? round($eta / 60) : null,
                'status' => $tracking->status,
            ]
        ]);
    }

    /**
     * Calculate route using LocationIQ
     */
    private function calculateRoute($fromLat, $fromLon, $toLat, $toLon)
    {
        try {
            $response = Http::get('https://us1.locationiq.com/v1/directions/driving/' . $fromLon . ',' . $fromLat . ';' . $toLon . ',' . $toLat, [
                'key' => $this->locationIqToken,
                'overview' => 'false',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['routes'][0])) {
                    return [
                        'distance' => $data['routes'][0]['distance'], // meters
                        'duration' => $data['routes'][0]['duration'], // seconds
                    ];
                }
            }
        } catch (\Exception $e) {
            \Log::error('LocationIQ API error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Geocode address using LocationIQ
     */
    public function geocodeAddress(Request $request)
    {
        $request->validate([
            'address' => 'required|string',
        ]);

        try {
            $response = Http::get('https://us1.locationiq.com/v1/search.php', [
                'key' => $this->locationIqToken,
                'q' => $request->address,
                'format' => 'json',
                'limit' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (count($data) > 0) {
                    return response()->json([
                        'success' => true,
                        'data' => [
                            'latitude' => $data[0]['lat'],
                            'longitude' => $data[0]['lon'],
                            'display_name' => $data[0]['display_name'],
                        ]
                    ]);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Address not found'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Geocoding failed: ' . $e->getMessage()
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
            'status' => 'sometimes|in:waiting_driver,driver_assigned,on_the_way,arrived,delivered',
            'notes' => 'nullable|string',
        ]);

        // Verify driver role
        if (!$request->user()->isDriver() && !$request->user()->isAdmin()) {
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

        // Verify driver owns this delivery
        if ($tracking->driver_id != $request->user()->id && !$request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Not your delivery'
            ], 403);
        }

        DB::beginTransaction();
        try {
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
                'created_at' => now(),
            ]);

            // Update order status based on tracking status
            if ($request->status === 'delivered') {
                $order->update(['status' => 'delivered']);
                
                // Send notification to customer
                $this->notificationService->sendToUser(
                    $order->user,
                    '🎉 Order Delivered',
                    "Your order #{$order->order_number} has been delivered!",
                    ['type' => 'order_status', 'order_id' => $order->id]
                );
            } elseif ($request->status === 'on_the_way') {
                $order->update(['status' => 'shipped']);
                
                $this->notificationService->sendToUser(
                    $order->user,
                    '🚚 Driver on the way',
                    "Driver is on the way to deliver your order #{$order->order_number}",
                    ['type' => 'order_status', 'order_id' => $order->id]
                );
            } elseif ($request->status === 'arrived') {
                $this->notificationService->sendToUser(
                    $order->user,
                    '📍 Driver Arrived',
                    "Driver has arrived at your location for order #{$order->order_number}",
                    ['type' => 'order_status', 'order_id' => $order->id]
                );
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

        $order = Order::findOrFail($orderId);
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

        DB::beginTransaction();
        try {
            $tracking->update([
                'driver_id' => $request->user()->id,
                'driver_name' => $request->user()->name,
                'driver_phone' => $request->user()->phone,
                'status' => 'driver_assigned',
            ]);

            TrackingHistory::create([
                'delivery_tracking_id' => $tracking->id,
                'status' => 'driver_assigned',
                'description' => "Driver {$request->user()->name} accepted the order",
                'created_at' => now(),
            ]);

            // Notify customer
            $this->notificationService->sendToUser(
                $order->user,
                '✅ Driver Assigned',
                "Driver {$request->user()->name} has been assigned to your order",
                ['type' => 'order_status', 'order_id' => $order->id]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order accepted successfully',
                'data' => $tracking->fresh()
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
     * DRIVER: Get available orders
     */
    public function getAvailableOrders(Request $request)
    {
        if (!$request->user()->isDriver()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $orders = Order::with(['items', 'address', 'user', 'tracking'])
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
     * DRIVER: Get my deliveries
     */
    public function getMyDeliveries(Request $request)
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
            ->get();

        return response()->json([
            'success' => true,
            'data' => $deliveries
        ]);
    }
}