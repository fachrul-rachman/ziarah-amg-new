<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), geolocation=(), microphone=()',
        );

        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set(
                'Referrer-Policy',
                'strict-origin-when-cross-origin',
            );
        }

        return $response;
    }
}
