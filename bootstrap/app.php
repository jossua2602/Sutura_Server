<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\RecordActivity;
use App\Http\Middleware\UpdateLastSeenAt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => CheckRole::class,
            'last.seen' => UpdateLastSeenAt::class,
        ]);

        // Stamp last_seen_at on every authenticated API request
        $middleware->appendToGroup('api', UpdateLastSeenAt::class);

        // Every successful change anyone makes goes to the admin Activity Log
        $middleware->appendToGroup('api', RecordActivity::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // With APP_DEBUG=true (the local default), any unhandled exception —
        // a bad route-model-binding id, a wrong HTTP verb, a raw DB error —
        // rendered a full stack trace with server file paths straight into
        // the JSON response. Found via live testing: a plain 404 (job order
        // that doesn't exist) and a 405 (no GET route for a single
        // appointment) both leaked one. ValidationException/Authentication/
        // AuthorizationException are left alone — Laravel's own default
        // rendering for those is already clean and the frontend depends on
        // ValidationException's field-level `errors` shape specifically.
        // Everything else gets a short message and the right status instead
        // of a trace, regardless of debug mode.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // Signed out or token expired: Laravel's own text is "Unauthenticated."
            if ($e instanceof AuthenticationException) {
                return response()->json(['success' => false, 'message' => 'Please sign in again.'], 401);
            }

            if ($e instanceof ValidationException
                || $e instanceof AuthorizationException) {
                return null;
            }

            $status = match (true) {
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => 404,
                $e instanceof MethodNotAllowedHttpException => 405,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            $message = match ($status) {
                404 => 'The requested resource was not found.',
                405 => 'This action is not supported for this endpoint.',
                default => 'Something went wrong. Please try again.',
            };

            // A deliberate abort(403|422|…, 'reason') carries a message written for the user — show it.
            // (404 / 405 / 5xx stay generic so route names and internals never leak.)
            if ($e instanceof HttpExceptionInterface && in_array($status, [400, 401, 403, 409, 422, 429], true) && trim($e->getMessage()) !== '') {
                $message = $e->getMessage();
            }

            // A FormRequest's authorize() failing arrives here as a 403 with
            // Laravel's default wording; say it plainly instead.
            if ($status === 403 && $message === 'This action is unauthorized.') {
                $message = "You don't have permission to do this.";
            }

            return response()->json(['success' => false, 'message' => $message], $status);
        });
    })->create();
