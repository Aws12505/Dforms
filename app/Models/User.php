<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'email',
        'default_language_id',
    ];

    // ID is not auto-incrementing since it comes from auth system
    public $incrementing = false;

    /**
     * User's default language preference
     */
    public function defaultLanguage()
    {
        return $this->belongsTo(Language::class, 'default_language_id');
    }

    /**
     * Roles assigned to this user (mirrored from auth system)
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /**
     * Permissions assigned to this user (mirrored from auth system)
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'user_permissions');
    }

    /**
     * Entries created by this user
     */
    public function entries()
    {
        return $this->hasMany(Entry::class, 'created_by_user_id');
    }
}
