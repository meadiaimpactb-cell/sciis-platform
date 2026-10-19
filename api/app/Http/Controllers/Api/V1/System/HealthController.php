<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/** BKP-03 — /health and /version for uptime monitors. */
class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        $checks = ['db' => 'ok', 'redis' => 'ok', 'storage' => 'ok', 'queue_lag' => 0];

        try {
            DB::select('select 1');
        } catch (\Throwable $e) {
            $checks['db'] = 'fail';
        }

        try {
            if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
                Redis::connection()->ping();
                $checks['queue_lag'] = (int) Redis::connection()->llen('queues:default');
            } else {
                $checks['redis'] = 'skipped';
            }
        } catch (\Throwable $e) {
            $checks['redis'] = 'fail';
        }

        try {
            Storage::disk((string) config('sciis.documents.disk', 'local'))->exists('.health');
        } catch (\Throwable $e) {
            $checks['storage'] = 'fail';
        }

        $status = in_array('fail', $checks, true) ? 'degraded' : 'ok';

        return response()->json(['status' => $status] + $checks + ['time' => now()->toIso8601String()], $status === 'ok' ? 200 : 503);
    }

    public function version(): JsonResponse
    {
        return response()->json([
            'name' => config('app.name'),
            'version' => config('app.version'),
            'git_sha' => config('app.git_sha'),
            'api' => 'v1',
            'php' => PHP_VERSION,
            'environment' => app()->environment(),
        ]);
    }
}
