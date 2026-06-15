<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'category' => ['required', Rule::in(['backup', 'security', 'device_refresh', 'new_service'])],
            'status' => ['required', Rule::in(['open', 'in_progress', 'won', 'lost', 'deferred'])],
            'display_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
