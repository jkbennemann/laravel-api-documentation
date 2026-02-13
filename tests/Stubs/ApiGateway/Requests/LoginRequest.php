<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\ApiGateway\Requests;

use Illuminate\Foundation\Http\FormRequest;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

#[Parameter(name: 'email', required: true, type: 'string', format: 'email', description: 'User email address')]
#[Parameter(name: 'password', required: true, type: 'string', description: 'User password')]
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string',
        ];
    }
}
