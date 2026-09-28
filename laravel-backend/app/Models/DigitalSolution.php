<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DigitalSolution extends Model
{
    use ApiSerializable;

    protected $fillable = [
        'title', 'slug', 'category', 'service_category', 'summary', 'description',
        'features', 'price_label', 'icon', 'is_published', 'sort_order',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
        'features' => 'array',
    ];

    public function inquiries(): HasMany
    {
        return $this->hasMany(DigitalSolutionInquiry::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'category' => $this->category,
            'service_category' => $this->service_category,
            'summary' => $this->summary,
            'description' => $this->description,
            'features' => $this->features,
            'price_label' => $this->price_label,
            'icon' => $this->icon,
            'is_published' => (bool) $this->is_published,
            'sort_order' => (int) $this->sort_order,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}