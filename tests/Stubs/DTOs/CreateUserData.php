<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class CreateUserData extends Data
{
    /**
     * @param string $name The user's full name
     * @param string $email The user's email address
     * @param string|null $password The user's password (optional for updates)
     * @param bool $isActive Whether the user should be active
     * @param array $preferences User preferences as key-value pairs
     */
    public function __construct(
        public string $name,
        public string $email,
        public ?string $password = null,
        public bool $isActive = true,
        public array $preferences = [],
    ) {}
}
