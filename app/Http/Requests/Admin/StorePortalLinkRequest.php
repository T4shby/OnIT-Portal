<?php

namespace App\Http\Requests\Admin;

use App\Enums\PortalLinkType;
use App\Enums\UserRole;
use App\Http\Requests\Concerns\ValidatesClientAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePortalLinkRequest extends FormRequest
{
    use ValidatesClientAccess;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'link_type' => ['required', Rule::enum(PortalLinkType::class)],
            'url' => [
                Rule::requiredIf(fn () => $this->input('link_type', 'external') === PortalLinkType::External->value),
                'nullable', 'string', 'max:2048',
                Rule::when($this->input('link_type') === PortalLinkType::External->value, ['url']),
            ],
            'icon' => ['nullable', 'string', 'max:100'],
            'required_role' => ['nullable', Rule::enum(UserRole::class)],
            'display_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
            'open_in_new_tab' => ['boolean'],
        ];
    }

    /**
     * null client_id = global link (every customer dashboard). Only Super Admins may
     * create those. When a client is given, require access so staff cannot create or
     * move a link into a client they are not assigned to.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! filled($this->input('client_id'))) {
                if ($this->user()?->role !== UserRole::SuperAdmin) {
                    $validator->errors()->add(
                        'client_id',
                        'Only a Super Admin can create or edit global portal links.',
                    );
                }

                return;
            }

            $this->validateClientAccessForActor((int) $this->input('client_id'));
        });
    }
}
