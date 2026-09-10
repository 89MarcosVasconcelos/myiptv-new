<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $fillable = ['type', 'reference', 'import_path', 'is_catalog', 'user_id'];

    protected $casts = [
        'is_catalog' => 'boolean',
    ];

    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
