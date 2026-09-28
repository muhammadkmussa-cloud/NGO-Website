<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Event;
use App\Models\Inquiry;
use App\Models\Leader;
use App\Models\MediaItem;
use App\Models\Volunteer;
use App\Services\JwtService;
use App\Services\YouTubeSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicController extends Controller
{
    public function __construct(protected YouTubeSyncService $youtube, protected JwtService $jwt)
    {
    }

    /** GET /api/public/blog */
    public function blog(Request $request): JsonResponse
    {
        $query = BlogPost::where('is_published', true);

        $category = $request->query('category');
        if ($category && $category !== 'All') {
            $query->where('category', $category);
        }

        $search = $request->query('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        $limit = max(1, (int) $request->query('limit', 50));

        return response()->json(
            $query->orderByDesc('created_at')->limit($limit)->get()->map->toApiArray()->values()
        );
    }

    /** GET /api/public/blog/{slug} */
    public function blogBySlug(string $slug): JsonResponse
    {
        $post = BlogPost::where('slug', $slug)->where('is_published', true)->first();

        if (!$post) {
            return response()->json(['detail' => 'Article not found'], 404);
        }

        return response()->json($post->toApiArray());
    }

    /** GET /api/public/events */
    public function events(): JsonResponse
    {
        return response()->json(
            Event::where('is_active', true)->get()->map->toApiArray()->values()
        );
    }

    /** GET /api/public/media */
    public function media(Request $request): JsonResponse
    {
        $refresh = filter_var($request->query('refresh', 'false'), FILTER_VALIDATE_BOOL);

        // F-03: the refresh flag triggers a destructive cache wipe + quota spend;
        // only honor it for a valid admin (anonymous callers are served the cache).
        if ($refresh && !$this->hasAdminToken($request)) {
            $refresh = false;
        }

        $category = $request->query('category');
        $limit = max(1, (int) $request->query('limit', 20));

        if ($refresh || MediaItem::count() === 0) {
            $this->youtube->fetchAndCacheChannelVideos(forceRefresh: $refresh);
        }

        $query = MediaItem::query();
        if ($category && $category !== 'All') {
            $query->where('category', $category);
        }

        return response()->json(
            $query->ordered()->limit($limit)->get()->map->toApiArray()->values()
        );
    }

    /** Lightweight admin-token check for parameter-level authorization (F-03). */
    protected function hasAdminToken(Request $request): bool
    {
        $token = $request->bearerToken();
        if (!$token) {
            return false;
        }

        $payload = $this->jwt->decode($token);

        return ($payload['sub'] ?? null) === config('roi.admin_email');
    }

    /** GET /api/public/media/latest */
    public function latestMedia(): JsonResponse
    {
        return response()->json(
            MediaItem::orderByDesc('published_at')->limit(3)->get()->map->toApiArray()->values()
        );
    }

    /** GET /api/public/media/refresh */
    public function refreshMedia(): JsonResponse
    {
        $this->youtube->fetchAndCacheChannelVideos(forceRefresh: true);

        return response()->json(
            MediaItem::query()->ordered()->get()->map->toApiArray()->values()
        );
    }

    /** GET /api/public/metrics — admin-editable impact metrics (settings-backed). */
    public function metrics(): JsonResponse
    {
        return response()->json(\App\Models\SiteSetting::publicPayload()['metrics']);
    }

    /** POST /api/public/volunteer → 201 */
    public function volunteer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string'],
            'email' => ['required', 'email:rfc'],
            'phone' => ['required', 'string'],
            'primary_skill' => ['required', 'string'],
            'availability' => ['required', 'string'],
            'motivation' => ['nullable', 'string'],
        ]);

        $volunteer = Volunteer::create($data);

        return response()->json($volunteer->toApiArray(), 201);
    }

    /** POST /api/public/contact → 201 */
    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'email' => ['required', 'email:rfc'],
            'subject' => ['nullable', 'string'],
            'message' => ['required', 'string'],
        ]);

        $inquiry = Inquiry::create($data);

        return response()->json($inquiry->toApiArray(), 201);
    }

    /** GET /api/public/leaders */
    public function leaders(): JsonResponse
    {
        return response()->json(
            Leader::orderBy('created_at')->get()->map->toApiArray()->values()
        );
    }

}
