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
            'entra_license_tier' => ['nullable', 'string', 'in:free,p1'],
            'entra_group_id' => ['nullable', 'uuid'],
            'entra_superops_app_id' => ['nullable', 'uuid'],
            'entra_sync_enabled' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['entra_tenant_id', 'entra_group_id', 'entra_superops_app_id', 'pax8_company_id', 'superops_account_id'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $trimmed[$field] = trim($value) === '' ? null : trim($value);
            }
        }

        if ($trimmed !== []) {
            $this->merge($trimmed);
        }
    }
}
