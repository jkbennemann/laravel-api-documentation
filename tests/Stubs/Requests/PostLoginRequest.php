<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc,dns,strict'],
            'password' => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'authx.login.email.required|default:The e-mail must be given',
            'email.email' => 'authx.login.email.email|default:The e-mail must have a valid format.',
            'password.required' => 'authx.login.password.required|default:The password must be given.',
        ];
    }
}
