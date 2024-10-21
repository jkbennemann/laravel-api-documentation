<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimpleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'parameter_1' => 'required|string',
            'parameter_2' => 'email',
        ];
    }
}
