<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Routing\Controller;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests\AttachSubscriptionEntitiesRequest;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\AttachedSubscriptionEntityResource;

class DynamicResponseController extends Controller
{
    public function attach(AttachSubscriptionEntitiesRequest $request): AttachedSubscriptionEntityResource
    {
        return new AttachedSubscriptionEntityResource([]);
    }
}
