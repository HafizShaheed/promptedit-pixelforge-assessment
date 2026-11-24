<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $fillable = [
        'event_id',
        'type',
        'pixelforge_generation_id',
        'payload_created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_created_at' => 'datetime',
        ];
    }
}
