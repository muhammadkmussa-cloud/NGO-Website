<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;

class MediaItem extends Model
{
    use ApiSerializable;

    public $timestamps = false; // Table carries only published_at

    protected $fillable = [
        'youtube_id', 'title', 'category', 'summary',
        'thumbnail_url', 'duration', 'is_featured', 'published_at',
    ];

    protected $casts = ['is_featured' => 'boolean', 'published_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $media) {
            $media->published_at ??= now();
            $media->duration ??= '5:30';
        });
    }

    public function scopeOrdered($query)
    {
        return $query->orderByDesc('is_featured')->orderByDesc('published_at');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'youtube_id' => $this->youtube_id,
            'title' => $this->title,
            'category' => $this->category,
            'summary' => $this->summary,
            'thumbnail_url' => $this->thumbnail_url,
            'duration' => $this->duration,
            'is_featured' => (bool) $this->is_featured,
            'published_at' => $this->iso($this->published_at),
        ];
    }
}
