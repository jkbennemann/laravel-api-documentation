<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Enums;

enum AttachedSubscriptionEntityType: string
{
    case PRODUCT = 'product';
    case SERVICE = 'service';
    case ADDON = 'addon';
}
