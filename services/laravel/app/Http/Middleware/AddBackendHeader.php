<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 在每個回應加上 X-Backend: laravel。
 * Go 與 Laravel 放在同一個 Nginx upstream 輪流接請求，排查與壓測時靠這個 header 分辨是誰回的。
 */
class AddBackendHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Backend', 'laravel');

        return $response;
    }
}
