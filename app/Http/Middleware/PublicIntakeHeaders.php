<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicIntakeHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->is('request-quote*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        return $response;
    }
}
