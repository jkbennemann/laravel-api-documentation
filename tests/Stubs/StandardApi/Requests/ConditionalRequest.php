<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConditionalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => 'required|string|in:individual,company',
            'company_name' => 'required_if:type,company|string|max:255',
            'tax_id' => 'required_with:company_name|string',
            'personal_id' => 'required_without:company_name|string',
            'notes' => 'nullable|string',
        ];
    }
}
