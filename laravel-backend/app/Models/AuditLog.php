<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use ApiSerializable;

    public const UPDATED_AT = null;

    protected $fillable = ['admin_email', 'action', 'details'];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->created_at ??= now();
        });
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'admin_email' => $this->admin_email,
            'action' => $this->action,
            'details' => $this->details,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
