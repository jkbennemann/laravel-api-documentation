<?php

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TestFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Basic string fields
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:32'],

            // Numeric fields
            'age' => ['required', 'integer', 'between:18,120'],
            'price' => ['nullable', 'numeric', 'min:0'],

            // Boolean field
            'active' => ['required', 'boolean'],

            // Enum field
            'status' => ['required', 'string', 'in:pending,approved,rejected'],

            // Date fields
            'birth_date' => ['required', 'date'],
            'appointment' => ['nullable', 'date_format:Y-m-d H:i:s'],

            // Array field
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'in:admin,user,editor'],

            // File field
            'avatar' => ['nullable', 'image', 'max:1024'], // 1MB
        ];
    }
}
