<?php

declare(strict_types=1);

use JkBennemann\LaravelApiDocumentation\Services\OpenApi;
use openapiphp\openapi\spec\RequestBody;

it('can generate a simplistic documentation file', function () {
    config()->set('api-documentation.title', 'Laravel API Documentation');
    config()->set('api-documentation.version', '1.0.0');
    config()->set('api-documentation.servers', [
        [
            'url' => 'http://localhost',
            'description' => 'Service',
        ],
    ]);

    $service = app(OpenApi::class);
    $routeData = json_decode('[{"method":"GET","uri":"route-1","summary":null,"description":null,"middlewares":[],"is_vendor":false,"request_parameters":[],"parameters":[],"tags":[],"documentation":null,"responses":[]}]', true);

    $service->processRoutes($routeData);
    $openApi = $service->get();

    expect($openApi)
        ->toBeInstanceOf(\openapiphp\openapi\spec\OpenApi::class)
        ->and($openApi->openapi)
        ->toBe('3.0.2')
        ->and($openApi->info->title)
        ->toBe('Laravel API Documentation')
        ->and($openApi->info->version)
        ->toBe('1.0.0')
        ->and($openApi->servers)
        ->toHaveCount(1)
        ->and($openApi->servers[0]->url)
        ->toBe('http://localhost')
        ->and($openApi->servers[0]->description)
        ->toBe('Service')
        ->and($openApi->paths)
        ->toHaveKeys(['/route-1'])
        ->and($openApi->paths['/route-1']->get->summary)
        ->toBe('')
        ->and($openApi->paths['/route-1']->get->description)
        ->toBe('')
        ->and($openApi->paths['/route-1']->get->responses)
        ->toHaveCount(1)
        ->and($openApi->paths['/route-1']->get->responses[200]->description)
        ->toBe('');
});

it('can generate a documentation file from a request parameter', function () {
    $service = app(OpenApi::class);
    $routeData = json_decode('[{"method":"GET","uri":"route-1","summary":null,"description":null,"middlewares":[],"is_vendor":false,"request_parameters":[],"parameters":{"parameter_1":{"name":"parameter_1","description":"The first parameter","type":"string","format":null,"required":true,"deprecated":false,"parameters":[]},"parameter_2":{"name":"parameter_2","description":"The second parameter","type":"string","format":"email","required":false,"deprecated":false,"parameters":[]}},"tags":[],"documentation":null,"responses":[]}]', true);

    $service->processRoutes($routeData);
    $openApi = $service->get();

    expect($openApi)
        ->toBeInstanceOf(\openapiphp\openapi\spec\OpenApi::class)
        ->and($openApi->paths)
        ->toHaveKeys(['/route-1'])
        ->and($openApi->paths['/route-1']->get->summary)
        ->toBe('')
        ->and($openApi->paths['/route-1']->get->description)
        ->toBe('')
        ->and($openApi->paths['/route-1']->get->responses)
        ->toHaveCount(1)
        ->and($openApi->paths['/route-1']->get->responses[200]->description)
        ->toBe('');
});

it('can generate a documentation file from for nested Body', function () {
    $service = app(OpenApi::class);
    $routeData = json_decode('[{"method":"POST","uri":"route-1","summary":null,"description":null,"middlewares":[],"is_vendor":false,"request_parameters":[],"parameters":{"base":{"name":"base","description":null,"type":"array","format":null,"required":true,"deprecated":false,"parameters":{"parameter_1":{"name":"parameter_1","description":null,"type":"string","format":null,"required":true,"deprecated":false,"parameters":[]},"parameter_2":{"name":"parameter_2","description":null,"type":"string","format":"email","required":false,"deprecated":false,"parameters":[]}}}},"tags":[],"documentation":null,"responses":[]}]', true);

    $service->processRoutes($routeData);
    $openApi = $service->get();

    expect($openApi)
        ->toBeInstanceOf(\openapiphp\openapi\spec\OpenApi::class)
        ->and($openApi->paths)
        ->toHaveKeys(['/route-1'])
        ->and($openApi->paths['/route-1']->post->summary)
        ->toBe('')
        ->and($openApi->paths['/route-1']->post->description)
        ->toBe('')
        ->and($openApi->paths['/route-1']->post->requestBody)
        ->toBeInstanceOf(RequestBody::class)
        ->and($openApi->paths['/route-1']->post->requestBody->content)
        ->toHaveKeys(['application/json'])
        ->and($openApi->paths['/route-1']->post->requestBody->content['application/json']->schema)
        ->toBeInstanceOf(\openapiphp\openapi\spec\Schema::class)
        ->and($openApi->paths['/route-1']->post->requestBody->content['application/json']->schema->type)
        ->toBe('object')
        ->and($openApi->paths['/route-1']->post->requestBody->content['application/json']->schema->properties)
        ->toHaveKeys(['base'])
        ->and($openApi->paths['/route-1']->post->requestBody->content['application/json']->schema->properties['base']->type)
        ->toBe('object')
        ->and($openApi->paths['/route-1']->post->requestBody->content['application/json']->schema->properties['base']->properties)
        ->toBeArray()
        ->toHaveKeys(['parameter_1', 'parameter_2'])
        ->and($openApi->paths['/route-1']->post->requestBody->content['application/json']->schema->properties['base']->properties['parameter_1']->type)
        ->toBe('string')
        ->and($openApi->paths['/route-1']->post->requestBody->content['application/json']->schema->properties['base']->properties['parameter_1']->format)
        ->toBeNull()
        ->and($openApi->paths['/route-1']->post->requestBody->content['application/json']->schema->properties['base']->properties['parameter_1']->deprecated)
        ->toBeFalse()
        ->and($openApi->paths['/route-1']->post->requestBody->content['application/json']->schema->properties['base']->properties['parameter_2']->type)
        ->toBe('string')
        ->and($openApi->paths['/route-1']->post->requestBody->content['application/json']->schema->properties['base']->properties['parameter_2']->format)
        ->toBe('email')
        ->and($openApi->paths['/route-1']->post->requestBody->content['application/json']->schema->properties['base']->properties['parameter_2']->deprecated)
        ->toBeFalse()
        ->and($openApi->paths['/route-1']->post->responses)
        ->toHaveCount(1)
        ->and($openApi->paths['/route-1']->post->responses[200]->description)
        ->toBe('');
});

it('can generate a documentation file with 200 response resource', function () {
    $service = app(OpenApi::class);
    $routeData = json_decode('[{"method":"GET","uri":"route-1","summary":null,"description":null,"middlewares":[],"is_vendor":false,"request_parameters":[],"parameters":[],"tags":[],"documentation":null,"responses":{"200":{"description":"A sample description","resource":"JkBennemann\\\\LaravelApiDocumentation\\\\Tests\\\\Stubs\\\\Resources\\\\SampleResource","headers":{"X-Header":"Some header"}}}}]', true);

    $service->processRoutes($routeData);
    $openApi = $service->get();

    expect($openApi)
        ->toBeInstanceOf(\openapiphp\openapi\spec\OpenApi::class)
        ->and($openApi->paths)
        ->toHaveKeys(['/route-1'])
        ->and($openApi->paths['/route-1']->get->summary)
        ->toBe('')
        ->and($openApi->paths['/route-1']->get->description)
        ->toBe('')
        ->and($openApi->paths['/route-1']->get->responses)
        ->toHaveCount(1)
        ->and($openApi->paths['/route-1']->get->responses[200]->description)
        ->toBe('A sample description')
        ->and($openApi->paths['/route-1']->get->responses[200]->headers)
        ->toHaveKeys(['X-Header']);
});
