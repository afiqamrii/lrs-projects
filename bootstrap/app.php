<?php

use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\PublicIntakeHeaders;
use App\Models\CompanySetting;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(PublicIntakeHeaders::class);
        $middleware->alias(['active' => EnsureActive::class]);
        $middleware->redirectUsersTo('/overview');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['confirmation_token', 'intake_token', 'code', 'state', 'client_secret', 'access_token', 'refresh_token']);
        $exceptions->respond(function (Response $response): Response {
            if (request()->is('request-quote*')) {
                if (in_array($response->getStatusCode(), [413, 419, 429], true)) {
                    $response = response()->view('public.error', ['settings' => CompanySetting::current(), 'code' => $response->getStatusCode()], $response->getStatusCode(), $response->headers->all());
                }
                $response->headers->set('Cache-Control', 'private, no-store');
                $response->headers->set('Referrer-Policy', 'no-referrer');
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
                $response->headers->set('X-Content-Type-Options', 'nosniff');
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
