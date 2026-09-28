<?php

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'roi.admin' => AdminAuth::class,
        ]);

        // Trust upstream proxies so Request::ip() is the real client (rate limits / password cap).
        $middleware->prepend(TrustProxies::class);

        // F-06: baseline security headers on every response (clickjacking, sniffing, referrer).
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->append(\App\Http\Middleware\RequestId::class);

        // 120 requests / minute per client IP across the API, matching FastAPI's
        // RateLimitMiddleware defaults (limiter defined in AppServiceProvider).
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // FastAPI wire-format parity: errors render as {"detail": ...}.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                // FastAPI wire format: {"detail": [{loc, msg, type}, ...]}
                $detail = [];
                foreach ($e->errors() as $field => $messages) {
                    foreach ((array) $messages as $message) {
                        $detail[] = [
                            'loc' => ['body', $field],
                            'msg' => $message,
                            'type' => 'value_error',
                        ];
                    }
                }

                return response()->json(['detail' => $detail], 422);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'detail' => 'Too Many Requests. Rate limit exceeded. Please wait a minute before retrying.',
                ], 429);
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException|BackedEnumCaseNotFoundException $e, Request $request) {
            if ($request->is('api/*') && !($e instanceof ModelNotFoundException && $e->getModel())) {
                return response()->json(['detail' => 'Not Found'], 404);
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['detail' => 'Method Not Allowed'], 405);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(
                    ['detail' => 'Could not validate global administrator credentials'],
                    401,
                    ['WWW-Authenticate' => 'Bearer']
                );
            }
        });
    })->create();
