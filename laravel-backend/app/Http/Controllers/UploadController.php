<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /**
     * POST /api/admin/uploads — multipart image upload for blog/event/hero media.
     * Stores on the public disk (web-accessible at /storage/...) and returns the URL.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);

        $file = $request->file('file');
        $name = Str::random(24) . '.' . strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $path = $file->storeAs('uploads', $name, 'public');

        if (!$path) {
            return response()->json(['detail' => 'Upload failed.'], 500);
        }

        $this->audit->record($this->adminEmail($request), 'media uploaded', $path);

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ], 201);
    }

    protected function adminEmail(Request $request): string
    {
        return (string) ($request->attributes->get('roi_admin_email')
            ?? config('roi.admin_email')
            ?? 'admin@example.com');
    }
}
