<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;

class BlogPost extends Model
{
    use ApiSerializable;

    protected $fillable = [
        'title', 'slug', 'summary', 'content', 'category',
        'author', 'image_url', 'is_published',
    ];

    protected $casts = ['is_published' => 'boolean'];

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'content' => $this->content,
            'category' => $this->category,
            'author' => $this->author,
            'image_url' => $this->image_url,
            'is_published' => (bool) $this->is_published,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
