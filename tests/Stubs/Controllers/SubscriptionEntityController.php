<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use JkBennemann\LaravelApiDocumentation\Attributes\AdditionalDocumentation;
use JkBennemann\LaravelApiDocumentation\Attributes\Description;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\AttachedSubscriptionEntity;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\AttachSubscriptionEntitiesCommandResult;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\HashId;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Enums\AttachedSubscriptionEntityType;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests\AttachSubscriptionEntitiesRequest;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\AttachedSubscriptionEntityResource;

#[Tag('Subscription')]
class SubscriptionEntityController
{
    private $commandBus;

    public function __construct()
    {
        // Mock command bus for testing
        $this->commandBus = new class
        {
            public function dispatch($command)
            {
                // Return mock result
                return new AttachSubscriptionEntitiesCommandResult([
                    new AttachedSubscriptionEntity(
                        new HashId('abc123'),
                        AttachedSubscriptionEntityType::PRODUCT,
                        'Test Product'
                    ),
                    new AttachedSubscriptionEntity(
                        new HashId('def456'),
                        AttachedSubscriptionEntityType::SERVICE,
                        'Test Service'
                    ),
                ]);
            }
        };
    }

    #[AdditionalDocumentation(url: 'https://example.com/docs', description: 'External documentation')]
    #[Description('Logs an user in. <br> This route requires a valid email and password.')]
    #[Tag('Test')]
    public function attach(AttachSubscriptionEntitiesRequest $request): AttachedSubscriptionEntityResource
    {
        return $this->runCommand(
            $request,
            static fn (HashId $hashId, AttachedSubscriptionEntity ...$attachedSubscriptionEntity) => new AttachSubscriptionEntitiesCommand($hashId, ...$attachedSubscriptionEntity)
        );
    }

    public function detach(AttachSubscriptionEntitiesRequest $request): AttachedSubscriptionEntityResource
    {
        return $this->runCommand(
            $request,
            static fn (HashId $hashId, AttachedSubscriptionEntity ...$attachedSubscriptionEntity) => new DetachSubscriptionEntitiesCommand($hashId, ...$attachedSubscriptionEntity)
        );
    }

    private function runCommand(AttachSubscriptionEntitiesRequest $request, callable $commandInstantiation): AttachedSubscriptionEntityResource
    {
        /** @var AttachSubscriptionEntitiesCommandResult $result */
        $result = $this->commandBus->dispatch(
            $commandInstantiation(
                new HashId($request->input('subscription_id')),
                ...array_map(
                    static fn (array $item) => new AttachedSubscriptionEntity(
                        new HashId($item['id']),
                        AttachedSubscriptionEntityType::from($item['type'])
                    ),
                    $request->input('items')
                )
            )
        );

        return new AttachedSubscriptionEntityResource($result->attachedEntities);
    }
}

// Mock command classes for testing
class AttachSubscriptionEntitiesCommand
{
    public HashId $hashId;

    public array $entities;

    public function __construct(HashId $hashId, AttachedSubscriptionEntity ...$entities)
    {
        $this->hashId = $hashId;
        $this->entities = $entities;
    }
}

class DetachSubscriptionEntitiesCommand
{
    public HashId $hashId;

    public array $entities;

    public function __construct(HashId $hashId, AttachedSubscriptionEntity ...$entities)
    {
        $this->hashId = $hashId;
        $this->entities = $entities;
    }
}
