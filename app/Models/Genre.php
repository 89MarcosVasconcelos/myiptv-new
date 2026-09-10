<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Genre extends Model
{
    protected $fillable = ['slug', 'name'];

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_genre');
    }
}
