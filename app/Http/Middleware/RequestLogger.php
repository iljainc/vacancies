<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\RequestLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class RequestLogger
{
    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);
        
        try {
            $response = $next($request);
            
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000; // в миллисекундах
            
            $this->logRequest($request, $response, $executionTime);
            
            return $response;
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;
            
            $this->logRequest($request, null, $executionTime, $e);
            
            throw $e; // пробрасываем ошибку дальше
        }
    }
    
    private function logRequest($request, $response, $executionTime, $exception = null)
    {
        try {
            RequestLog::create([
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip_address' => $request->ip(),
                'real_ip' => $request->header('X-Forwarded-For') ?: $request->header('X-Real-IP') ?: $request->ip(),
                'user_agent' => $request->userAgent(),
                'status_code' => $response && method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 500,
                'execution_time' => round($executionTime, 2),
                'response_size' => $response && method_exists($response, 'getContent') ? strlen($response->getContent()) : 0,
                'request_data' => json_encode($request->except(['files', 'file_1', 'file_2', 'file_3', 'file_4', 'file_5']), JSON_UNESCAPED_UNICODE),
                'response_data' => $response && method_exists($response, 'getContent') ? $response->getContent() : null,
                'user_id' => Auth::check() ? Auth::id() : null,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log request: ' . $e->getMessage());
        }
    }
}
