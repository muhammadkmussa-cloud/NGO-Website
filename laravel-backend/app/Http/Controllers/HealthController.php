<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /** GET /api/health — liveness. Production omits internal engine details. */
    public function health(): JsonResponse
    {
        $environment = (string) config('roi.environment');
        $payload = [
            'status' => 'online',
            'project' => config('roi.project_name'),
            'version' => (string) config('roi.project_version', '2.0.0'),
            'location' => 'Harbor City, Kenya',
            'security_controls' => 'Rate Limiting (120/min) + Immutable Audit Logs + W3C CORS Verified',
        ];

        if (! $this->isProduction($environment)) {
            $dbUrl = (string) (config('database.connections.'.config('database.default').'.database') ?? '');
            $payload['environment'] = $environment;
            $payload['database_engine'] = $dbUrl !== '' ? preg_replace('/(:[^:@\/]+)@/', ':***@', $dbUrl) : $dbUrl;
        }

        return response()->json($payload);
    }

    /** GET /api/ready — readiness for load balancers (DB ping, no secrets). */
    public function ready(): JsonResponse
    {
        $database = 'ok';
        $ok = true;
        try {
            DB::connection()->getPdo();
            DB::select('select 1 as ok');
        } catch (Throwable $e) {
            $database = 'error';
            $ok = false;
        }

        return response()->json([
            'status' => $ok ? 'ready' : 'degraded',
            'checks' => [
                'database' => $database,
            ],
        ], $ok ? 200 : 503);
    }

    protected function isProduction(string $environment): bool
    {
        return strtolower($environment) === 'production';
    }
}
