<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasFactory;

    public const STATUS_PENDING_SUBMIT = 'pending_submit';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'pixelforge_id',
        'prompt',
        'size',
        'quality',
        'reference_images',
        'status',
        'input_tokens',
        'output_tokens',
        'estimated_price',
        'actual_price',
        'image_url',
        'stored_image_path',
        'error_code',
        'error_message',
        'idempotency_key',
        'last_webhook_event_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_price' => 'float',
            'actual_price' => 'float',
            'last_webhook_event_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_SUBMIT,
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
        ], true);
    }
}
