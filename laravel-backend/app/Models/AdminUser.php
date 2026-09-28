<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;

class AdminUser extends Model
{
    use ApiSerializable;

    public $timestamps = false;

    protected $fillable = ['email', 'password_hash', 'last_login'];

    protected $casts = ['last_login' => 'datetime'];

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'password_hash' => $this->password_hash,
            'last_login' => $this->iso($this->last_login),
        ];
    }
}
