<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests;

use Illuminate\Foundation\Http\FormRequest;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

class NestedParametersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'base' => 'required|array',
            'base.parameter_1' => 'required|string',
            'base.parameter_2' => 'email',
        ];
    }
}
