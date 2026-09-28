<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;

class DigitalSolutionFaq extends Model
{
    use ApiSerializable;

    protected $fillable = [
        'question', 'answer', 'group', 'sort_order', 'is_published',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question,
            'answer' => $this->answer,
            'group' => $this->group,
            'sort_order' => (int) $this->sort_order,
            'is_published' => (bool) $this->is_published,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}