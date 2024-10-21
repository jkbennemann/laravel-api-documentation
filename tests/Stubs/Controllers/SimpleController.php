<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Routing\Controller;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\SampleResource;

class SimpleController extends Controller
{
    public function simple(): SampleResource
    {
        return new SampleResource([]);
    }

    #[Tag('My-Tag')]
    public function tag(): SampleResource
    {
        return new SampleResource([]);
    }

    #[Tag(['My-Tag', 'Another-Tag'])]
    public function tags(): SampleResource
    {
        return new SampleResource([]);
    }

    #[Tag('My-Tag,Another-Tag')]
    public function stringTags(): SampleResource
    {
        return new SampleResource([]);
    }
}
