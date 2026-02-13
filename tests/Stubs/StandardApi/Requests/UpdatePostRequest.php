<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest with partial update rules — no attributes, no annotations.
 * Uses 'sometimes' for optional partial updates.
 */
class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'status' => 'sometimes|string|in:draft,published',
        ];
    }
}
