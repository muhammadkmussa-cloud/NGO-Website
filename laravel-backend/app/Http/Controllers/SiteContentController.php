<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteContentController extends Controller
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /** GET /api/public/site — public hero + metrics content. */
    public function show(): JsonResponse
    {
        return response()->json(SiteSetting::publicPayload());
    }

    /** GET /api/admin/site — same payload for the console editor. */
    public function adminShow(): JsonResponse
    {
        return response()->json(SiteSetting::publicPayload());
    }

    /** PUT /api/admin/site — persist hero + metrics overrides. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hero_eyebrow' => ['sometimes', 'nullable', 'string', 'max:120'],
            'hero_title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'hero_description' => ['sometimes', 'nullable', 'string', 'max:600'],
            'hero_image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'metric_youth_mentored' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'metric_events_hosted' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'metric_individuals_supported' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'metric_active_volunteers' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ]);

        if ($data === []) {
            return response()->json(['detail' => 'No supported fields supplied.'], 422);
        }

        SiteSetting::putMany($data);
        $this->audit->record($this->adminEmail($request), 'site content updated', implode(', ', array_keys($data)));

        return response()->json(SiteSetting::publicPayload());
    }

    protected function adminEmail(Request $request): string
    {
        return (string) ($request->attributes->get('roi_admin_email')
            ?? config('roi.admin_email')
            ?? 'admin@example.com');
    }
}
