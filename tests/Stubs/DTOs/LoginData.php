<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs;

use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
#[Parameter(name: 'id', type: 'string', description: 'The hash id of the user', example: '2Q1DG07Z')]
#[Parameter(name: 'email', type: 'string', format: 'email', description: 'The email of the user')]
#[Parameter(name: 'trashboard_id', required: false, type: 'int', description: 'The trashboard ID of the user', example: 13804)]
#[Parameter(name: 'attributes', type: 'array', description: 'The attributes of the user', example: [['name' => 'external_identifier', 'data' => null, 'value' => 'RB123456']])]
#[Parameter(name: 'roles', type: 'array', description: 'The roles of the user', example: [['role' => 'SUPER_ADMIN', 'expires_at' => null]])]
class LoginData extends Data
{
    public function __construct(
        public string $id,
        public string $email,
        public ?int $trashboardId = null,
        public array $attributes = [],
        public array $roles = [],
    ) {}

    public function defaultWrap(): string
    {
        return 'data';
    }
}
