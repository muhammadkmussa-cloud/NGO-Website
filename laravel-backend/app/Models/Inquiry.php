<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{
    use ApiSerializable;

    public const UPDATED_AT = null;

    protected $fillable = ['name', 'email', 'subject', 'message', 'status'];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $inquiry) {
            $inquiry->subject ??= 'General Inquiry';
            $inquiry->status ??= 'New';
            $inquiry->created_at ??= now();
        });
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'subject' => $this->subject,
            'message' => $this->message,
            'status' => $this->status,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
