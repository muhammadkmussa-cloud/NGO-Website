<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalPortfolioItem extends Model
{
    use ApiSerializable;

    protected $fillable = [
        'digital_solution_id', 'title', 'slug', 'client', 'location', 'year',
        'summary', 'outcome', 'image_url', 'is_published', 'is_featured', 'sort_order',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function solution(): BelongsTo
    {
        return $this->belongsTo(DigitalSolution::class, 'digital_solution_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'digital_solution_id' => $this->digital_solution_id,
            'solution_title' => $this->solution?->title,
            'title' => $this->title,
            'slug' => $this->slug,
            'client' => $this->client,
            'location' => $this->location,
            'year' => $this->year,
            'summary' => $this->summary,
            'outcome' => $this->outcome,
            'image_url' => $this->image_url,
            'is_published' => (bool) $this->is_published,
            'is_featured' => (bool) $this->is_featured,
            'sort_order' => (int) $this->sort_order,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
