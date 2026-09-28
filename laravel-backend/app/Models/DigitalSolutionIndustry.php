<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;

class DigitalSolutionIndustry extends Model
{
    use ApiSerializable;

    protected $fillable = [
        'name', 'icon', 'summary', 'sort_order', 'is_published',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,
            'summary' => $this->summary,
            'sort_order' => (int) $this->sort_order,
            'is_published' => (bool) $this->is_published,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}