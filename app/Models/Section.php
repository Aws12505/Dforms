<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    protected $fillable = [
        'stage_id',
        'name',
        'visibility_condition',
        'order'
    ];

    protected $casts = [
        'visibility_condition' => 'array',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(Field::class);
    }
}
