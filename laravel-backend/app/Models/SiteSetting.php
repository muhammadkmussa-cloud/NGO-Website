<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $fillable = ['key', 'value'];

    /** Default site content — used until an admin saves overrides. */
    public const DEFAULTS = [
        'hero_eyebrow' => 'Flagship Conference 2026',
        'hero_title' => 'Vijana Na Maadili',
        'hero_description' => 'Uniting 500+ coastal youth for mentorship, ethical leadership grounding, and digital career advancement.',
        'hero_image_url' => 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=900&q=80',
        'metric_youth_mentored' => '120',
        'metric_events_hosted' => '2',
        'metric_individuals_supported' => '95',
        'metric_active_volunteers' => '45',
    ];

    /** Keys an admin is allowed to write. */
    public const WRITABLE = [
        'hero_eyebrow', 'hero_title', 'hero_description', 'hero_image_url',
        'metric_youth_mentored', 'metric_events_hosted',
        'metric_individuals_supported', 'metric_active_volunteers',
    ];

    /** Raw key => value map with defaults applied. */
    public static function resolved(): array
    {
        $stored = static::query()->pluck('value', 'key')->all();

        return array_merge(self::DEFAULTS, array_intersect_key($stored, array_flip(self::WRITABLE)));
    }

    /** Persist a validated subset of whitelisted keys. */
    public static function putMany(array $values): void
    {
        foreach (array_intersect_key($values, array_flip(self::WRITABLE)) as $key => $value) {
            static::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }
    }

    /** Shape returned to the public SPA and the admin console. */
    public static function publicPayload(): array
    {
        $v = self::resolved();

        return [
            'hero' => [
                'eyebrow' => (string) $v['hero_eyebrow'],
                'title' => (string) $v['hero_title'],
                'description' => (string) $v['hero_description'],
                'image_url' => (string) $v['hero_image_url'],
            ],
            'metrics' => [
                'youth_mentored' => (int) $v['metric_youth_mentored'],
                'events_hosted' => (int) $v['metric_events_hosted'],
                'individuals_supported' => (int) $v['metric_individuals_supported'],
                'active_volunteers' => (int) $v['metric_active_volunteers'],
            ],
        ];
    }
}
