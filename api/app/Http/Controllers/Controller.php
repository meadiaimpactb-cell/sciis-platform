<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /** @param array<string, mixed> $data */
    protected function ok(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    protected function message(string $message, int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    protected function perPage(Request $request): int
    {
        $max = (int) config('sciis.pagination.max', 100);
        $default = (int) config('sciis.pagination.default', 20);

        return max(1, min($max, (int) $request->integer('per_page', $default)));
    }
}
