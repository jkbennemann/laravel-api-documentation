<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Routing\Controller;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests\NestedParametersRequest;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests\SimpleRequest;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests\SimpleStringParameterRequest;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\SampleResource;

class RequestParameterController extends Controller
{
    public function simple(SimpleRequest $request): SampleResource
    {
        return new SampleResource([]);
    }

    public function stringParameter(SimpleStringParameterRequest $request): SampleResource
    {
        return new SampleResource([]);
    }

    public function simpleNestedParameters(NestedParametersRequest $request): SampleResource
    {
        return new SampleResource([]);
    }
}
