<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Form extends Model
{
    protected $fillable = [
        'name',
        'category_id',
        'is_archived',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function formVersions(): HasMany
    {
        return $this->hasMany(FormVersion::class);
    }

    public function entries(): HasManyThrough
{
    return $this->hasManyThrough(
        Entry::class,
        FormVersion::class,
        'form_id',          // FK on form_versions table
        'form_version_id',  // FK on entries table
        'id',               // PK on forms table
        'id'                // PK on form_versions table
    );
}
}
