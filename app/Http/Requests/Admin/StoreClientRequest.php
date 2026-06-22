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
            'pax8_company_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'pax8_sso_enabled' => ['boolean'],
            'entra_tenant_id' => ['nullable', 'uuid'],
            'entra_group_id' => ['nullable', 'uuid'],
            'entra_sync_enabled' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
