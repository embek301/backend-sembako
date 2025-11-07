<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebaseService
{
    protected $messaging;
    protected $database;

    public function __construct()
    {
        $credentialsPath = storage_path('app/firebase/firebase-credentials.json');
        
        if (!file_exists($credentialsPath)) {
            throw new \Exception('Firebase credentials file not found at: ' . $credentialsPath);
        }

        $factory = (new Factory)->withServiceAccount($credentialsPath);
        
        // Initialize messaging
        $this->messaging = $factory->createMessaging();
        
        // Initialize database (optional)
        if (config('firebase.database_url')) {
            $factory = $factory->withDatabaseUri(config('firebase.database_url'));
            $this->database = $factory->createDatabase();
        }
    }

    /**
     * Send notification to single device
     */
    public function sendToDevice(string $fcmToken, string $title, string $body, array $data = [])
    {
        try {
            $notification = Notification::create($title, $body);
            
            $message = CloudMessage::withTarget('token', $fcmToken)
                ->withNotification($notification)
                ->withData($data);

            $this->messaging->send($message);
            
            return ['success' => true];
        } catch (\Exception $e) {
            \Log::error('Firebase send notification error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send notification to multiple devices
     */
    public function sendToMultipleDevices(array $fcmTokens, string $title, string $body, array $data = [])
    {
        try {
            $notification = Notification::create($title, $body);
            
            $message = CloudMessage::new()
                ->withNotification($notification)
                ->withData($data);

            $this->messaging->sendMulticast($message, $fcmTokens);
            
            return ['success' => true];
        } catch (\Exception $e) {
            \Log::error('Firebase send multicast error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send notification to topic
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = [])
    {
        try {
            $notification = Notification::create($title, $body);
            
            $message = CloudMessage::withTarget('topic', $topic)
                ->withNotification($notification)
                ->withData($data);

            $this->messaging->send($message);
            
            return ['success' => true];
        } catch (\Exception $e) {
            \Log::error('Firebase send to topic error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Subscribe device to topic
     */
    public function subscribeToTopic(string $fcmToken, string $topic)
    {
        try {
            $this->messaging->subscribeToTopic($topic, $fcmToken);
            return ['success' => true];
        } catch (\Exception $e) {
            \Log::error('Firebase subscribe error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}