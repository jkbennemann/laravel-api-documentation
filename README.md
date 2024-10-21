# Laravel API documentation

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jkbennemann/laravel-api-documentation.svg?style=flat-square)](https://packagist.org/packages/jkbennemann/laravel-api-documentation)
[![Total Downloads](https://img.shields.io/packagist/dt/jkbennemann/laravel-api-documentation.svg?style=flat-square)](https://packagist.org/packages/jkbennemann/laravel-api-documentation)

This is an opinionated package to generate API documentation for Laravel applications.
They key focus is to remove the need to manually write documentation and keep it up to date by generating it from the code.

## Installation

You can install the package via composer:

```bash
composer require jkbennemann/laravel-api-documentation
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-api-documentation-config"
```
By default it will set the settings to the following:

- Ignore all routes that are registered by a vendor package
- Ignore all routes with HTTP methods `HEAD`, `OPTIONS`
- UIs for swagger or redoc are disabled
- If enabled the UIs are available at
  - `/documentation` (defaults to swagger)
  - `/documentation/redoc` (if enabled)
  - `/documentation/swagger` (if enabled)

### Linking the storage's public directory

The package will store the generated documentation in the storage folder. To link the storage folder you can run:

```bash
php artisan storage:link
```

This will ensure that the generated documentation is accessible.  
When generating the documentation the package will store the documentation in `storage/app/public/api-documentation.json`.

In order for this to work you need to have the `public` disk configured in your `config/filesystems.php`.  
This should be already configured by default by your application.
```php
# config/filesystems.php

//...
'public' => [
    'driver' => 'local',
    'root' => storage_path('app/public'),
    'url' => env('APP_URL') . '/storage',
    'visibility' => 'public',
    'throw' => false,
],
//...
```

**As the documentation is stored in the `storage` folder of your application which by default will not be added to the VCS  
it is needed to explicitly exclude the `api-documentation.json` from your `storage/app/public/.gitignore` file.**

```bash
# storage/app/public/.gitignore

*
!.gitignore
!api-documentation.json
```

## Generating the documentation
Generating the documentation is as simple as running the following command:
```bash
php artisan documentation:generate
```

In case you want to be able to let your application generate the documentation through composer scripts  
you can add the following to your `composer.json`:
```json
{
    "scripts": {
        "documentation:generate": [
            "@php artisan documentation:generate"
        ]
    }
}
```


## How it works
The package will scan all routes of your application and generate an OpenAPI file containing the documentation.

Under the hood the package utilizes the PHP Parser to parse the routes and their controllers.  
It then walks through Request and Response classes to figure out the structure of the request and response objects.

With PHP 8 attributes it is possible to add additional information to the routes which will enhance the generated documentation.  
These attributes are defined by the package and can be used for Controller methods, Request classes and Response classes.

In addition to that we will also include the authentication middleware of a route if it is defined in the route and add it to the OpenAPI file for each route.

The package will then generate an OpenAPI file containing the documentation.

---

*The functionality is still limited and will not cover all scenarios you need to be covered.  
Feel free to open an issue or PR if you have a feature request or found a bug.*  
<br>
See the [Roadmap](#roadmap) section for more information on planned feature updates.  
For more information on the OpenAPI specification see [OpenAPI Specification](https://swagger.io/specification/).

## Available attributes

### Route attributes

#### 1. Tag
This attribute can be used to add the route to a specific tag.  
*Multiple tags can be added to a route, which will however add them multiple times to the OpenAPI file!*

```php
# SampleLoginController.php
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;

//..
#[Tag('Authentication')]
public function login()
{
    //...
}
```
This will add a new field to the route object in the OpenAPI file:
```json
{
    //...
    "\/login": {
        "post": {
            "tags": [
                "Authentication"
            ],
            //...
        }
    }
}
```

#### 2. Summary
This attribute can be used to add a summary to the route.

```php
# SampleLoginController.php
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;

//..
#[Summary('Login a user')]
public function login()
{
    //...
}
```
This will add a new field to the route object in the OpenAPI file:
```json
{
    //...
    "\/login": {
        "post": {
            "summary": "Login a user",
            //...
        }
    }
}
```


#### 3. Description
This attribute can be used to add a more detailed description to the route.  
This attribute also supports HTML.

```php
# SampleLoginController.php
use JkBennemann\LaravelApiDocumentation\Attributes\Description;

//..
#[Description('Logs an user in. <br> This route requires a valid email and password.')]
public function login()
{
    //...
}
```
This will add a new field to the route object in the OpenAPI file:
```json
{
    //...
    "\/login": {
        "post": {
            "description": "Logs an user in. <br> This route requires a valid email and password.",
            //...
        }
    }
}
```


#### 4. AdditionalDocumentation
This attribute can be used to add additional documentation to the route which are pointing to any external resources.
```php
# SampleController.php
use JkBennemann\LaravelApiDocumentation\Attributes\AdditionalDocumentation;

//..
#[AdditionalDocumentation(url: 'https://example.com/docs', description: 'External documentation')]
public function index()
{
    //...
}
```
This will add a new field to the route object in the OpenAPI file:
```json
{
    //...
    "\/login": {
        "post": {
            "externalDocs": {
                "url": "https://example.com/docs",
                "description": "External documentation"
            }
            //...
        }
    }
}
```


#### 5. DataResponse
This attribute can be specified multiple data responses for a route.  
In general this is used to provide more detailed information about the response for a given status code.  
*Multiple DataResponse attributes can be added to a route to specify responses for multiple status codes*

Available parameters:
- (required) `status` (int) - The status code of the response
- `description` (string) - A description of the response; *default:`''`*
- `resource` (null | string | array) - The resource class that is returned by the route (e.g. `UserResource::class`, `['id' => 'string']`, `null`); *default:`[]`*
- `headers` (null | array) - An array of response headers that are returned by the route (e.g. `['X-Token' => 'string']`); *default:`[]`*

```php
# SampleController.php
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;

//..
#[DataResponse(200, description: 'Logged in user information', resource: UserResource::class, headers: ['X-Token' => 'Token for the user to be used to issue API calls',])]
#[DataResponse(401, description: 'Failed Authentication', resource: ['error' => 'string'])]
public function index()
{
    //...
}
```
This will add a new field to the route object in the OpenAPI file:
```json
{
    //...
    "\/login": {
        "post": {
            //...
            "responses": {
                "200": {
                    "description": "Logged in user information",
                    "headers": {
                        "X-Token": {
                            "description": "Token for the user to be used to issue API calls",
                            "schema": {
                                "type": "string"
                            }
                        }
                    },
                    "content": {
                        "application\/json": {
                            "schema": {
                                //data of the UserResource.php
                            }
                        }
                    }
                },
                "401": {
                    "description": "Failed Authentication",
                    "headers": {},
                    "content": {
                        "application\/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "error": {
                                        "type": "string"
                                    }
                                }
                            }
                        }
                    }
                }
            }
            //...
        }
    }
}
```

### Request/Resource attributes

For request or resource classes the same attributes can be used as for the routes, except for the `Tag` attribute.  
In addition to that the following attributes are available:

#### 1. Parameter
This attribute can be used to specify additional information about a parameter.

Available parameters:
- (required) `name` (string) - The name of the parameter
- `required` (boolean) - Whether the parameter is required or not; *default:`false`*
- `description` (string) - A description of the parameter; *default:`''`*
- `type` (string) - The type of the parameter; *default:`'string'`*
- `format` (string) - The format of the parameter, considered as the sub-type as of OpenAPI; *default:`null`*
- `example` (mixed) - An example value for the parameter; *default:`null`*
- `deprecated` (boolean) - Whether the parameter is deprecated or not; *default:`false`*


```php
# LoginUserRequest.php
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

//..
#[Parameter(name: 'email', required: true, format: 'email', description: 'The email of the user', example: 'hello@example.com')]
#[Parameter(name: 'password', required: true, description: 'The password of the user')]
#[Parameter(name: 'confirm_token', required: true, description: 'The confirmation token. This is not used any longer!', deprecated: true)]
public function rules(): array
{
    return [
        'email' => [
            'required',
            'email',
        ],
        'password' => 'required',
    ];
}
```

```php
# UserResource.php
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

#[Parameter(name: 'id', type: 'string', format: 'uuid', description: 'The user ID', example: '123e4567-e89b-12d3-a456-426614174000')]
#[Parameter(name: 'email', type: 'string', format: 'email', description: 'The users email address')]
#[Parameter(name: 'attributes', type: 'array', description: 'Additional attributes assigned to the user', example: [])]
public function toArray($request): array|JsonSerializable|Arrayable
{
    return [
        'id' => $this->id,
        'email' => $this->email,
        'attributes' => $this->userAttributes ?? [],
    ];
}
```

## Roadmap
- [ ] Add support providing examples for request
- [ ] Add support for handling inline controller validation rules
- [ ] Adjusting the logic to determine the response structure with its types and formats correctly
- [ ] ...

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Jakob Bennemann](https://github.com/jkbennemann)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
