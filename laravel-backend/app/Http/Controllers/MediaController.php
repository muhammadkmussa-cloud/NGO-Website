<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Services\JwtService;
use App\Services\YouTubeSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MediaController extends Controller
{
    public function __construct(
        protected YouTubeSyncService $youtube,
        protected JwtService $jwt,
    ) {
    }

    /**
     * POST /api/youtube/cron-sync — automated scheduler engine endpoint.
     * F-03: requires the YOUTUBE_CRON_SECRET in production (query `secret` or
     * X-Cron-Secret header); open in local development for sandbox stubs.
     */
    public function cronSync(Request $request): JsonResponse
    {
        $secret = (string) config('roi.youtube_cron_secret');
        $isProduction = strtolower((string) config('roi.environment')) === 'production';

        if ($secret !== '') {
            $provided = (string) ($request->query('secret') ?: $request->header('X-Cron-Secret', ''));
            if (!hash_equals($secret, $provided)) {
                return response()->json(['detail' => 'Invalid cron sync secret'], 403);
            }
        } elseif ($isProduction) {
            // Fail closed: an untriggerable-by-design endpoint must not ship open.
            return response()->json(['detail' => 'YOUTUBE_CRON_SECRET must be configured in production'], 403);
        }

        return response()->json($this->youtube->fetchAndCacheChannelVideos(forceRefresh: false));
    }

    /** GET /api/youtube/channel-videos?refresh= */
    public function channelVideos(Request $request): JsonResponse
    {
        // H-2: refresh triggers a destructive cache wipe (MediaItem table) +
        // live YouTube API spend. Only honor it for a valid admin — anonymous
        // callers are served from the local cache (same policy as /public/media).
        $refresh = filter_var($request->query('refresh', 'false'), FILTER_VALIDATE_BOOL);
        if ($refresh && !$this->hasAdminToken($request)) {
            $refresh = false;
        }

        // H-2: never spend YouTube quota on routine public reads — sync only when
        // an admin explicitly refreshed or the local cache is empty.
        if ($refresh || MediaItem::count() === 0) {
            $syncResult = $this->youtube->fetchAndCacheChannelVideos(forceRefresh: $refresh);
        } else {
            $syncResult = [
                // Same semantic/status shape as the quota-guard payload so API
                // consumers see one stable "served from local cache" contract.
                'status' => 'synchronized_local_cache',
                'cached_items_count' => MediaItem::count(),
                'channel_id' => (string) config('roi.youtube_channel_id'),
            ];
        }

        $videos = MediaItem::query()->ordered()->get()->map(fn (MediaItem $v) => [
            'id' => $v->id,
            'youtube_id' => $v->youtube_id,
            'title' => $v->title,
            'category' => $v->category,
            'summary' => $v->summary,
            'thumbnail_url' => $v->thumbnail_url,
            'duration' => $v->duration,
            'is_featured' => (bool) $v->is_featured,
            'published_at' => $v->published_at
                ? $v->published_at->format('Y-m-d\TH:i:s')
                : Carbon::now('UTC')->format('Y-m-d\TH:i:s'),
        ])->values();

        return response()->json([
            'sync_status' => $syncResult,
            'videos' => $videos,
        ]);
    }

    /** Lightweight admin-token check for parameter-level authorization (H-2, mirrors PublicController F-03). */
    protected function hasAdminToken(Request $request): bool
    {
        $token = $request->bearerToken();
        if (!$token) {
            return false;
        }

        $payload = $this->jwt->decode($token);

        return ($payload['sub'] ?? null) === config('roi.admin_email');
    }
}
