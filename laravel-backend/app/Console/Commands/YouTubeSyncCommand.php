<?php

namespace App\Console\Commands;

use App\Services\YouTubeSyncService;
use Illuminate\Console\Command;

class YouTubeSyncCommand extends Command
{
    protected $signature = 'roi:youtube-sync {--force : Wipe cached media before syncing}';

    protected $description = 'Synchronize the ROI TV media cache with the YouTube Data API v3 channel feed';

    public function handle(YouTubeSyncService $youtube): int
    {
        $result = $youtube->fetchAndCacheChannelVideos(forceRefresh: (bool) $this->option('force'));
        $this->info(json_encode($result));

        return self::SUCCESS;
    }
}
