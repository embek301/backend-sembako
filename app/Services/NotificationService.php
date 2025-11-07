<?php
// app/Services/NotificationService.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected $expoUrl = 'https://exp.host/--/api/v2/push/send';

    /**
     * Send notification to single user
     */
    public function sendToUser($user, $title, $body, $data = [])
    {
        if (!$user->expo_push_token) {
            Log::warning("User {$user->id} has no push token");
            return false;
        }

        return $this->sendNotification(
            $user->expo_push_token,
            $title,
            $body,
            $data
        );
    }

    /**
     * Send notification to multiple users
     */
    public function sendToMultipleUsers($users, $title, $body, $data = [])
    {
        $tokens = $users->pluck('expo_push_token')->filter()->values()->toArray();
        
        if (empty($tokens)) {
            Log::warning('No valid push tokens found');
            return false;
        }

        return $this->sendBulkNotification($tokens, $title, $body, $data);
    }

    /**
     * Send notification via Expo API
     */
    protected function sendNotification($token, $title, $body, $data = [])
    {
        try {
            $response = Http::post($this->expoUrl, [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'sound' => 'default',
                'badge' => 1,
                'priority' => 'high',
                'channelId' => $data['type'] ?? 'default',
            ]);

            $result = $response->json();

            if (isset($result['data']['status']) && $result['data']['status'] === 'error') {
                Log::error('Expo notification error', $result['data']);
                return false;
            }

            Log::info('Notification sent successfully', ['token' => $token]);
            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send notification: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send bulk notifications
     */
    protected function sendBulkNotification($tokens, $title, $body, $data = [])
    {
        try {
            $messages = array_map(function($token) use ($title, $body, $data) {
                return [
                    'to' => $token,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                    'sound' => 'default',
                    'badge' => 1,
                ];
            }, $tokens);

            $response = Http::post($this->expoUrl, $messages);
            
            Log::info('Bulk notification sent', ['count' => count($tokens)]);
            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send bulk notification: ' . $e->getMessage());
            return false;
        }
    }
}