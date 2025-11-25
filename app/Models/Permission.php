<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'description',
    ];

    // ID is not auto-incrementing since it comes from auth system
    public $incrementing = false;

    /**
     * Users with this permission
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_permissions');
    }
}
