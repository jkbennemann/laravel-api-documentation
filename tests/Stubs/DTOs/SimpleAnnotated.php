<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs;

use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
#[Parameter(name: 'name', type: 'string', description: 'The name')]
#[Parameter(name: 'description', type: 'string', description: 'The description')]
#[Parameter(name: 'age', type: 'integer', description: 'The age')]
#[Parameter(name: 'active', type: 'boolean', description: 'Whether active')]
class SimpleAnnotated extends Data
{
    public function __construct(
        public string $name,
        public string $description,
        public int $age,
        public bool $active,
    ) {}
}
