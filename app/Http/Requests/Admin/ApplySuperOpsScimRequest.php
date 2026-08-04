<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ApplySuperOpsScimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scim_tenant_url' => ['required', 'url', 'max:500', 'regex:/^https:\/\/.+/i'],
            'scim_secret_token' => ['required', 'string', 'min:8', 'max:512'],
        ];
    }

    public function messages(): array
    {
        return [
            'scim_tenant_url.regex' => 'SCIM Tenant URL must be an https SuperOps SCIM URL (from Generate Tokens).',
        ];
    }

    protected function prepareForValidation(): void
    {
        $url = $this->input('scim_tenant_url');
        $token = $this->input('scim_secret_token');

        $this->merge([
            'scim_tenant_url' => is_string($url) ? rtrim(trim($url), '/') : $url,
            'scim_secret_token' => is_string($token) ? trim($token) : $token,
        ]);
    }
}
