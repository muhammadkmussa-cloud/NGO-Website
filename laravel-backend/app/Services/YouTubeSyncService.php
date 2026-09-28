<?php

namespace App\Services;

use App\Models\MediaItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class YouTubeSyncService
{
    /**
     * Ports fetch_and_cache_channel_videos from routers/media.py:
     * - Quota guard on unconfigured/placeholder API keys (serves local relational cache)
     * - Search API + batched Videos contentDetails lookup
     * - ISO 8601 duration parsing
     * - Keyword-priority auto categorization
     * - Newest video becomes featured; force_refresh wipes cache first
     * - All failures degrade gracefully to a fallback_cache status payload.
     */
    public function fetchAndCacheChannelVideos(bool $forceRefresh = false): array
    {
        $apiKey = (string) config('roi.youtube_api_key');
        $channelId = (string) config('roi.youtube_channel_id');

        if ($apiKey === '' || str_starts_with($apiKey, 'AIzaSyLiveProduction') || str_starts_with($apiKey, 'your_')) {
            return [
                'status' => 'synchronized_local_cache',
                'cached_items_count' => MediaItem::count(),
                'channel_id' => $channelId,
                'message' => 'Quota protection active. Using local PostgreSQL relational cache.',
            ];
        }

        try {
            $searchResponse = Http::timeout(10)->get('https://www.googleapis.com/youtube/v3/search', [
                'key' => $apiKey,
                'channelId' => $channelId,
                'part' => 'snippet,id',
                'order' => 'date',
                'maxResults' => 15,
                'type' => 'video',
            ]);

            if ($searchResponse->status() !== 200) {
                throw new \RuntimeException('YouTube Search API query failed');
            }

            $items = collect($searchResponse->json('items', []));

            $videoIds = $items
                ->filter(fn ($item) => data_get($item, 'id.kind') === 'youtube#video')
                ->map(fn ($item) => data_get($item, 'id.videoId'))
                ->values();

            $durationsMap = [];
            if ($videoIds->isNotEmpty()) {
                $detailsResponse = Http::timeout(10)->get('https://www.googleapis.com/youtube/v3/videos', [
                    'key' => $apiKey,
                    'id' => $videoIds->implode(','),
                    'part' => 'contentDetails',
                ]);

                if ($detailsResponse->status() === 200) {
                    foreach ($detailsResponse->json('items', []) as $videoItem) {
                        $rawDuration = data_get($videoItem, 'contentDetails.duration', 'PT5M30S');
                        $durationsMap[$videoItem['id']] = $this->parseIso8601Duration($rawDuration);
                    }
                }
            }

            if ($forceRefresh) {
                MediaItem::query()->delete();
            }

            $newCount = 0;
            foreach ($items->values() as $idx => $item) {
                $youtubeId = data_get($item, 'id.videoId');
                if (!$youtubeId) {
                    continue;
                }

                $snippet = data_get($item, 'snippet', []);
                $existing = MediaItem::where('youtube_id', $youtubeId)->first();
                if ($existing) {
                    continue;
                }

                $title = (string) data_get($snippet, 'title', '');
                $description = (string) data_get($snippet, 'description', '');
                $haystack = strtolower($title . ' ' . $description);

                // Categorize taxonomy based on title/description keywords (priority order preserved).
                $category = 'Community Outreach';
                if (str_contains($haystack, 'mentor') || str_contains($haystack, 'career')) {
                    $category = 'Mentorship Sessions';
                } elseif (str_contains($haystack, 'event') || str_contains($haystack, 'conference') || str_contains($haystack, 'vijana') || str_contains($haystack, 'maadili')) {
                    $category = 'Events & Conferences';
                } elseif (str_contains($haystack, 'iftar') || str_contains($haystack, 'distribution') || str_contains($haystack, 'ramadhan') || str_contains($haystack, 'feeding')) {
                    $category = 'Community Outreach';
                } elseif (str_contains($haystack, 'learn') || str_contains($haystack, 'tech') || str_contains($haystack, 'saving') || str_contains($haystack, 'finance')) {
                    $category = 'Educational Content';
                } elseif (str_contains($haystack, 'success') || str_contains($haystack, 'alumni') || str_contains($haystack, 'spotlight')) {
                    $category = 'Success Stories';
                }

                MediaItem::create([
                    'youtube_id' => $youtubeId,
                    'title' => $title,
                    'category' => $category,
                    'summary' => $description !== '' ? $description : 'Reaching Out Initiative broadcast special.',
                    'thumbnail_url' => data_get($snippet, 'thumbnails.high.url'),
                    'duration' => $durationsMap[$youtubeId] ?? '5:30',
                    'is_featured' => $idx === 0, // Make newest video featured
                ]);
                $newCount++;
            }

            return [
                'status' => 'success',
                'fetched_from_live_api' => true,
                'new_videos_cached' => $newCount,
            ];
        } catch (Throwable $e) {
            Log::warning('YouTube sync degraded to fallback cache', ['error' => $e->getMessage()]);

            return [
                'status' => 'fallback_cache',
                'error' => $e->getMessage(),
                'cached_items_count' => MediaItem::count(),
            ];
        }
    }

    /**
     * Converts ISO 8601 durations (e.g. PT12M45S) into H:MM:SS / MM:SS display strings.
     */
    public function parseIso8601Duration(string $duration): string
    {
        if (!preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $duration, $m)) {
            return '5:30';
        }

        $h = (int) ($m[1] ?? 0);
        $min = (int) ($m[2] ?? 0);
        $s = (int) ($m[3] ?? 0);

        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $min, $s)
            : sprintf('%d:%02d', $min, $s);
    }
}
