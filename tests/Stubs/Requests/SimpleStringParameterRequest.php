<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests;

use Illuminate\Foundation\Http\FormRequest;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

class SimpleStringParameterRequest extends FormRequest
{
    #[Parameter(name: 'parameter_1', description: 'The first parameter', required: true)]
    #[Parameter(name: 'parameter_2', description: 'The second parameter', format: 'email', required: false)]
    public function rules(): array
    {
        return [
            'parameter_1' => 'required|string',
            'parameter_2' => 'email',
        ];
    }
}
