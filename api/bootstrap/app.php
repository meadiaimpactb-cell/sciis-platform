<?php

declare(strict_types=1);

use App\Exceptions\DomainException;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureFullyVerified;
use App\Http\Middleware\EnsureMemberApproved;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->throttleApi('api');
        $middleware->trustProxies(at: '*');

        $middleware->api(prepend: [
            RequestId::class,
            SetLocale::class,
        ]);

        $middleware->api(append: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'verified.full' => EnsureFullyVerified::class,
            'member.approved' => EnsureMemberApproved::class,
            '2fa.enforced' => EnsureTwoFactor::class,
            'feature' => EnsureFeatureEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API-only application: every error is rendered as JSON (RFC-7807-ish shape).
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e): bool => true);

        $exceptions->dontReport([DomainException::class]);

        $exceptions->render(function (Throwable $e, Request $request): JsonResponse {
            $requestId = (string) $request->attributes->get('request_id', '');

            $payload = match (true) {
                $e instanceof ValidationException => [
                    'status' => 422,
                    'message' => __('messages.errors.validation'),
                    'errors' => $e->errors(),
                    'code' => 'validation_failed',
                ],
                $e instanceof DomainException => [
                    'status' => $e->status(),
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                    'code' => $e->code(),
                ],
                $e instanceof AuthenticationException => [
                    'status' => 401,
                    'message' => __('messages.errors.unauthenticated'),
                    'errors' => new stdClass,
                    'code' => 'unauthenticated',
                ],
                $e instanceof AuthorizationException => [
                    'status' => 403,
                    'message' => __('messages.errors.forbidden'),
                    'errors' => new stdClass,
                    'code' => 'forbidden',
                ],
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [
                    'status' => 404,
                    'message' => __('messages.errors.not_found'),
                    'errors' => new stdClass,
                    'code' => 'not_found',
                ],
                $e instanceof ThrottleRequestsException => [
                    'status' => 429,
                    'message' => __('messages.errors.too_many_requests'),
                    'errors' => new stdClass,
                    'code' => 'too_many_requests',
                ],
                $e instanceof HttpExceptionInterface => [
                    'status' => $e->getStatusCode(),
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : match ($e->getStatusCode()) {
                        401 => __('messages.errors.unauthenticated'),
                        403 => __('messages.errors.forbidden'),
                        404 => __('messages.errors.not_found'),
                        429 => __('messages.errors.too_many_requests'),
                        default => __('messages.errors.http'),
                    },
                    'errors' => new stdClass,
                    'code' => match ($e->getStatusCode()) {
                        401 => 'unauthenticated',
                        403 => 'forbidden',
                        404 => 'not_found',
                        419 => 'csrf_token_mismatch',
                        429 => 'too_many_requests',
                        default => 'http_error',
                    },
                ],
                default => [
                    'status' => 500,
                    'message' => config('app.debug') ? $e->getMessage() : __('messages.errors.server'),
                    'errors' => new stdClass,
                    'code' => 'server_error',
                ],
            };

            $body = [
                'message' => $payload['message'],
                'errors' => $payload['errors'],
                'code' => $payload['code'],
                'request_id' => $requestId,
            ];

            if (config('app.debug') && $payload['status'] === 500) {
                $body['exception'] = $e::class;
                $body['trace'] = collect($e->getTrace())->take(10)->map(fn (array $f): string => ($f['file'] ?? '?').':'.($f['line'] ?? '?'))->all();
            }

            $headers = ['X-Request-Id' => $requestId];
            if ($e instanceof HttpExceptionInterface) {
                $headers = array_merge($e->getHeaders(), $headers);
            }

            return new JsonResponse($body, $payload['status'], $headers);
        });
    })
    ->create();
