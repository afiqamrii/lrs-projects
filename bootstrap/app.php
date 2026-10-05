<?php

use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\PublicIntakeHeaders;
use App\Models\CompanySetting;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
        $exceptions->render(function (ValidationException $error, Request $request): ?Response {
            if ($request->isMethod('POST')) {
                $parameters = $request->route()?->parameters() ?? [];
                $inquiry = $parameters['inquiry'] ?? null;
                $source = $request->integer('mail_message_id') ?: null;
                $review = match ($request->route()?->getName()) {
                    'lifecycle.decision.save' => route('lifecycle.decision', [$inquiry, $parameters['revision'], 'message' => $source]),
                    'lifecycle.vendor.save' => route('lifecycle.vendor', [$inquiry, $parameters['reconfirmation'], 'message' => $source]),
                    'lifecycle.handoff.save', 'lifecycle.handoff.approve', 'lifecycle.event' => route('lifecycle.handoff', [$inquiry, 'message' => $source]),
                    'lifecycle.message.approve' => route('lifecycle.message', [$inquiry, $parameters['message']]),
                    'lifecycle.message.save' => $parameters['kind'] === 'reconfirmation' ? route('lifecycle.vendor', [$inquiry, $parameters['parent']]) : route('lifecycle.booking.compose', $inquiry),
                    'settings.handoff.save' => route('settings.handoff'),
                    'quotations.approve' => route('quotations.review', [$inquiry, $parameters['revision']]),
                    'operations.control' => route('operations.health'),
                    default => null,
                };
                if ($review) {
                    $error->redirectTo($review);
                }
            }

            return null;
        });
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
