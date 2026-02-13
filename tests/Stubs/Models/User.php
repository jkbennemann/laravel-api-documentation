<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $fillable = [
        'id',
        'name',
        'email',
        'password',
        'email_verified_at',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [
        'display_name',
    ];

    public function getDisplayNameAttribute(): string
    {
        return $this->name.' ('.$this->email.')';
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
