<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Http;

class Channel extends Model
{
    protected $fillable = [
        'playlist_id', 'name', 'description', 'url', 'url_hash', 'channel_number', 'stream_type',
        'http_headers', 'country_id', 'mode_id', 'content_type_id',
        'language_id', 'subtitle_id', 'status', 'consecutive_failures', 'last_checked_at',
    ];

    protected $casts = [
        'http_headers' => 'array',
        'last_checked_at' => 'datetime',
    ];

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(ChannelCheck::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function mode(): BelongsTo
    {
        return $this->belongsTo(Mode::class);
    }

    public function contentType(): BelongsTo
    {
        return $this->belongsTo(ContentType::class);
    }

    /** Genero agora e N-pra-N: um canal pode ter varios (ex.: "Ação, Aventura"). */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'channel_genre');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function subtitle(): BelongsTo
    {
        return $this->belongsTo(Subtitle::class);
    }
}
