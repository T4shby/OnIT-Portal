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
     * client_id is optional here (null = global link, which PortalLinkPolicy lets any
     * staff member manage). When a client is given, require access to it so staff
     * cannot create or move a link into a client they are not assigned to.
     */
    public function withValidator($validator): void
    {
        $validator->after(function () {
            if (filled($this->input('client_id'))) {
                $this->validateClientAccessForActor((int) $this->input('client_id'));
            }
        });
    }
}
