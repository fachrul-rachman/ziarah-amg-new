<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AllowConfiguredEmbedding
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredOrigins = Setting::query()->find(1)?->embed_allowed_origins;
        $origins = is_array($configuredOrigins)
            ? array_values(array_filter(
                $configuredOrigins,
                fn (mixed $origin): bool => $this->isSafeOrigin($origin),
            ))
            : [];

        $response = $next($request);
        $response->headers->set(
            'Content-Security-Policy',
            'frame-ancestors '.($origins === [] ? "'none'" : implode(' ', $origins)),
        );
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    private function isSafeOrigin(mixed $origin): bool
    {
        if (! is_string($origin)
            || filter_var($origin, FILTER_VALIDATE_URL) === false
            || str_contains($origin, "\r")
            || str_contains($origin, "\n")) {
            return false;
        }

        $parts = parse_url($origin);

        return is_array($parts)
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && isset($parts['host'])
            && ! isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            && ($parts['path'] ?? '') === '';
    }
}
