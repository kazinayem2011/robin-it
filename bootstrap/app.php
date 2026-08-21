<?php

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\ApiEnvelope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'admin' => AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Customer-facing problems: out of stock, expired coupon, empty cart.
        $exceptions->render(function (StorefrontException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiEnvelope::error($e->getMessage(), $e->status(), $e->errorCode(), $e->context());
            }

            return back()->with('error', $e->getMessage());
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiEnvelope::error(
                    $e->validator->errors()->first() ?: 'Please check the highlighted fields and try again.',
                    422,
                    ApiCode::VALIDATION_ERROR,
                    ['errors' => $e->errors()]
                );
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiEnvelope::error('Please sign in to continue.', 401, ApiCode::UNAUTHORIZED);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiEnvelope::error(
                    'You do not have permission to do that.',
                    403,
                    ApiCode::FORBIDDEN
                );
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiEnvelope::error('We could not find what you were looking for.', 404, ApiCode::NOT_FOUND);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiEnvelope::error('We could not find what you were looking for.', 404, ApiCode::NOT_FOUND);
            }
        });

        // Anything unexpected: never leak the internal message to the customer.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                return null;
            }

            if (config('app.debug')) {
                return null;
            }

            return ApiEnvelope::error(
                'Something went wrong on our end. Please try again, or contact support if it keeps happening.',
                500,
                ApiCode::SERVER_ERROR
            );
        });
    })->create();
