<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs;

class AttachSubscriptionEntitiesCommandResult
{
    public function __construct(
        public readonly array $attachedEntities
    ) {}
}
