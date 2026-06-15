<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'superops_account_id' => ['nullable', 'string', 'max:255'],
            'superops_sso_enabled' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
