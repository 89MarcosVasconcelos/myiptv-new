<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelCheck extends Model
{
    protected $fillable = [
        'channel_id', 'status', 'http_status', 'detected_content_type',
        'ffprobe_ok', 'message', 'checked_at',
    ];

    protected $casts = [
        'ffprobe_ok' => 'boolean',
        'checked_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
