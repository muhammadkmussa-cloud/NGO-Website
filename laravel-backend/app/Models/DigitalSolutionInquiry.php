<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalSolutionInquiry extends Model
{
    use ApiSerializable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'digital_solution_id', 'name', 'email', 'phone',
        'organization', 'message', 'status', 'notes',
        'quoted_amount', 'follow_up_at', 'status_changed_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'follow_up_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'quoted_amount' => 'float',
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
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'organization' => $this->organization,
            'message' => $this->message,
            'status' => $this->status,
            'notes' => $this->notes,
            'quoted_amount' => $this->quoted_amount !== null ? (float) $this->quoted_amount : null,
            'follow_up_at' => $this->iso($this->follow_up_at),
            'status_changed_at' => $this->iso($this->status_changed_at),
            'is_open' => \App\Services\SolutionInquiryWorkflow::isOpen((string) $this->status),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
