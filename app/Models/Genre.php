<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Genre extends Model
{
    protected $fillable = ['slug', 'name'];

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }
}
