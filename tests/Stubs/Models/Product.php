<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models;

use Illuminate\Database\Eloquent\Model;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Casts\MoneyCast;

class Product extends Model
{
    protected $fillable = [
        'id',
        'slug',
        'name',
        'price',
        'created_at',
        'updated_at',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected $casts = [
        'price' => MoneyCast::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
