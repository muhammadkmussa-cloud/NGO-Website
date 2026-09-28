<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;

class Leader extends Model
{
    use ApiSerializable;

    public const UPDATED_AT = null;

    protected $fillable = ['name', 'role', 'bio'];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $leader) {
            $leader->created_at ??= now();
        });
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'bio' => $this->bio,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
