<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

class EncryptSensitiveData
{
    /**
     * Sensitive fields that should be encrypted
     *
     * @var array
     */
    protected $sensitiveFields = [
        'ssn', 
        'social_security', 
        'tax_id', 
        'passport_number', 
        'credit_card', 
        'card_number',
        'health_data',
        'medical_record',
        'personal_id',
        'id_number'
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        
        // Only process JSON responses
        if ($this->isJsonResponse($response)) {
            $content = $response->getContent();
            $data = json_decode($content, true);
            
            if (is_array($data)) {
                $data = $this->processData($data);
                $response->setContent(json_encode($data));
            }
        }
        
        return $response;
    }
    
    /**
     * Check if the response is JSON
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return bool
     */
    protected function isJsonResponse(Response $response): bool
    {
        return strpos($response->headers->get('Content-Type', ''), 'application/json') !== false 
            || $response instanceof \Illuminate\Http\JsonResponse;
    }
    
    /**
     * Process data recursively to encrypt sensitive fields
     *
     * @param  array  $data
     * @return array
     */
    protected function processData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->processData($value);
            } elseif (is_string($value) && in_array(strtolower($key), $this->sensitiveFields)) {
                // Only encrypt if not already encrypted
                if (!$this->isEncrypted($value)) {
                    $data[$key] = Crypt::encrypt($value);
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Check if a value is already encrypted
     *
     * @param  string  $value
     * @return bool
     */
    protected function isEncrypted(string $value): bool
    {
        try {
            Crypt::decrypt($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
