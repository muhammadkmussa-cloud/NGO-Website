<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;

class Volunteer extends Model
{
    use ApiSerializable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'full_name', 'email', 'phone', 'primary_skill',
        'availability', 'motivation', 'status',
    ];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $volunteer) {
            $volunteer->status ??= 'Pending Review';
            $volunteer->created_at ??= now();
        });
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'primary_skill' => $this->primary_skill,
            'availability' => $this->availability,
            'motivation' => $this->motivation,
            'status' => $this->status,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
