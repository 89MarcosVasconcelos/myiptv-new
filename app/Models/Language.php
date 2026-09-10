<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Language extends Model
{
    protected $fillable = ['code', 'name'];

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }
}
