<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs;

use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Enums\AttachedSubscriptionEntityType;

class AttachedSubscriptionEntity
{
    public function __construct(
        public readonly HashId $hashId,
        public readonly AttachedSubscriptionEntityType $type,
        public ?string $title = null
    ) {}
}
