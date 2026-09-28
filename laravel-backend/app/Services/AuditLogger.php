<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditLogger
{
    /**
     * Best-effort audit insert; failures are swallowed so they never break a request
     * (exact parity with backend.auth.record_audit_log).
     */
    public function record(string $email, string $action, ?string $details = null): void
    {
        try {
            AuditLog::create([
                'admin_email' => $email,
                'action' => $action,
                'details' => $details,
            ]);
        } catch (Throwable $e) {
            Log::warning('Audit log write failed', ['error' => $e->getMessage()]);
        }
    }
}
