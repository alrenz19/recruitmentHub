<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SmsService
{
    protected $endpoint;
    protected $token;
    protected $usePassword;

    public function __construct()
    {
        $this->endpoint = config('sms.traccar_endpoint');
        $this->token = config('sms.traccar_token');
        $this->usePassword = config('sms.traccar_password_protected');
    }

    public function sendSms(string $phone, string $message)
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($this->usePassword) {
            // Use Basic Auth if app password is enabled
            $response = Http::withBasicAuth('', $this->token)
                ->post($this->endpoint.'/send', [
                    'to' => $phone,
                    'message' => $message
                ]);
        } else {
            // Use Cloud Bearer token
            $headers['Authorization'] = 'Bearer '.$this->token;
            $response = Http::withHeaders($headers)
                ->post($this->endpoint.'/send', [
                    'to' => $phone,
                    'message' => $message
                ]);
        }

        return $response->json();
    }
}
