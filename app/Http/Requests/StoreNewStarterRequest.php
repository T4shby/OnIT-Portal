<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNewStarterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('contact-support')
            && filled($user->client?->superops_account_id);
    }

    public function rules(): array
    {
        return [
            'starter_name' => ['required', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'starter_email' => ['nullable', 'email', 'max:255'],
            'equipment_access' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'starter_name.required' => 'Please enter the new starter\'s full name.',
        ];
    }
}
