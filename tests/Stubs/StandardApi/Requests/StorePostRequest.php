<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Standard FormRequest — no attributes, no annotations.
 * This is what `php artisan make:request StorePostRequest` produces.
 */
class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'string|in:draft,published',
        ];
    }
}
