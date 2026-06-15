<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecommendationRequest extends FormRequest
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
            'category' => ['required', Rule::in(['security', 'service', 'technology'])],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'display_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
