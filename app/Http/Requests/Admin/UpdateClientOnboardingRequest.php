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
            'checkpoints' => ['required', 'array'],
        ];

        foreach (ClientOnboardingService::MANUAL_CHECKPOINTS as $key) {
            $rules["checkpoints.{$key}"] = ['boolean'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $checkpoints = [];

        foreach (ClientOnboardingService::MANUAL_CHECKPOINTS as $key) {
            $checkpoints[$key] = $this->boolean("checkpoints.{$key}");
        }

        $this->merge(['checkpoints' => $checkpoints]);
    }
}
