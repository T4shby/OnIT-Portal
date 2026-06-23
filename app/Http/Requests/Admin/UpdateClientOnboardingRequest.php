<?php

namespace App\Http\Requests\Admin;

use App\Services\ClientOnboardingService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'checkpoints' => ['present', 'array'],
        ];

        foreach (ClientOnboardingService::MANUAL_CHECKPOINTS as $key) {
            $rules["checkpoints.{$key}"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $submitted = $this->input('checkpoints', []);

        if (! is_array($submitted)) {
            return;
        }

        $normalized = [];

        foreach ($submitted as $key => $value) {
            if (in_array($key, ClientOnboardingService::MANUAL_CHECKPOINTS, true)) {
                $normalized[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }

        $this->merge(['checkpoints' => $normalized]);
    }
}
