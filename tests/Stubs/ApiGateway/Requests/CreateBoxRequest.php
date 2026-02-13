<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\ApiGateway\Requests;

use Illuminate\Foundation\Http\FormRequest;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

#[Parameter(name: 'title', required: true, type: 'string', description: 'The name of the box', example: 'My WordPress Box')]
#[Parameter(name: 'callback_url', required: false, type: 'string', format: 'uri', description: 'URL to notify when provisioning completes')]
class CreateBoxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:150',
            'callback_url' => 'sometimes|string',
        ];
    }
}
