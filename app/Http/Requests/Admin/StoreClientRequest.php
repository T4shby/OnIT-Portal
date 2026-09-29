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
            'dropsuite_organization_id' => ['nullable', 'string', 'max:255'],
            'huntress_organization_id' => ['nullable', 'string', 'max:255'],
            'entra_tenant_id' => ['nullable', 'uuid'],
            'entra_license_tier' => ['nullable', 'string', 'in:free,p1'],
            'entra_group_id' => ['nullable', 'uuid'],
            'entra_superops_app_id' => ['nullable', 'uuid'],
            'entra_superops_sso_app_id' => ['nullable', 'uuid'],
            'entra_sync_enabled' => ['boolean'],
            'is_active' => ['boolean'],
            'products' => ['nullable', 'array'],
            'products.superops' => ['nullable', 'boolean'],
            'products.m365' => ['nullable', 'boolean'],
            'products.huntress' => ['nullable', 'boolean'],
            'products.dropsuite' => ['nullable', 'boolean'],
            'products.pax8' => ['nullable', 'boolean'],
        ];
    }

    /**
     * External account / tenant ids that decide whose data a portal client shows
     * (tickets, devices, incidents, backups, M365 directory, Pax8 launch).
     */
    public const EXTERNAL_MAPPING_FIELDS = [
        'superops_account_id',
        'huntress_organization_id',
        'dropsuite_organization_id',
        'pax8_company_id',
        'entra_tenant_id',
    ];

    /**
     * An external id may belong to one portal client only. Otherwise an account
     * manager could point an assigned client at an unassigned client's account and
     * read that tenant's data through it, and a copy-paste slip by anyone would show
     * one customer another customer's tickets. Only values that change are checked,
     * so a client with a pre-existing duplicate can still be saved and fixed.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $current = $this->route('client');
            $current = $current instanceof \App\Models\Client ? $current : null;

            foreach (self::EXTERNAL_MAPPING_FIELDS as $field) {
                $value = $this->input($field);
                if (! is_string($value) || $value === '' || $validator->errors()->has($field)) {
                    continue;
                }

                $normalized = strtolower($value);
                if ($current !== null && strtolower((string) $current->{$field}) === $normalized) {
                    continue;
                }

                $takenElsewhere = \App\Models\Client::query()
                    ->whereRaw('LOWER('.$field.') = ?', [$normalized])
                    ->when($current !== null, fn ($q) => $q->whereKeyNot($current->getKey()))
                    ->exists();

                if ($takenElsewhere) {
                    $validator->errors()->add($field, 'This ID is already linked to another client. Each external account can belong to one portal client only.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['entra_tenant_id', 'entra_group_id', 'entra_superops_app_id', 'entra_superops_sso_app_id', 'pax8_company_id', 'superops_account_id', 'dropsuite_organization_id', 'huntress_organization_id'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $trimmed[$field] = trim($value) === '' ? null : trim($value);
            }
        }

        $products = $this->input('products');
        if (is_array($products)) {
            $normalized = [];
            foreach (\App\Services\Portal\ClientProductService::KEYS as $key) {
                if (array_key_exists($key, $products)) {
                    $normalized[$key] = filter_var($products[$key], FILTER_VALIDATE_BOOLEAN);
                }
            }
            $trimmed['products'] = $normalized;
        }

        if ($trimmed !== []) {
            $this->merge($trimmed);
        }
    }
}
