<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Routing\Controller;
use JkBennemann\LaravelApiDocumentation\Attributes\Description;
use JkBennemann\LaravelApiDocumentation\Attributes\PathParameter;
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;
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

    #[Summary('My Summary')]
    public function summary(): SampleResource
    {
        return new SampleResource([]);
    }

    #[Description('My Description')]
    public function description(): SampleResource
    {
        return new SampleResource([]);
    }

    #[PathParameter(name: 'id', description: 'The ID of the resource', type: 'integer')]
    public function parameter(int $id): SampleResource
    {
        return new SampleResource([]);
    }

    #[PathParameter(name: 'id', description: 'The ID of the resource', type: 'int', required: false)]
    public function optionalParameter(int $id = null): SampleResource
    {
        return new SampleResource([]);
    }

    #[PathParameter(name: 'paramOne', description: 'The first parameter', type: 'int')]
    #[PathParameter(name: 'paramTwo', description: 'The second parameter', type: 'string', format: 'uuid', required: false)]
    public function multiParameter(int $paramOne, ?string $paramTwo = null): SampleResource
    {
        return new SampleResource([]);
    }

    #[PathParameter(name: 'mail', format: 'email', description: 'The first parameter', example: 'mail@test.com')]
    public function mailExampleParameter(string $mail): SampleResource
    {
        return new SampleResource([]);
    }
}
