<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests;

use Illuminate\Foundation\Http\FormRequest;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Enums\AttachedSubscriptionEntityType;
use Illuminate\Validation\Rules\Enum;

class AttachSubscriptionEntitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['subscription_id' => $this->route('subscriptionId')]);
    }

    public function rules(): array
    {
        return [
            'subscription_id' => [
                'required',
                'string',
            ],
            'items' => [
                'required',
                'array',
            ],
            'items.*' => [
                'required',
                'array',
            ],
            'items.*.id' => [
                'required',
                'string',
            ],
            'items.*.type' => [
                'required',
                new Enum(AttachedSubscriptionEntityType::class),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'subscription_id.required' => 'The subscription ID field is required.',
            'subscription_id.string' => 'The subscription ID field must be a string.',
            'items.array' => 'The items field must be an array.',
            'items.required' => 'The items field is required.',
            'items.*.id.required' => 'The item ID field is required.',
            'items.*.id.string' => 'The item ID field must be a string.',
            'items.*.type.required' => 'The item type field is required.',
        ];
    }
}
