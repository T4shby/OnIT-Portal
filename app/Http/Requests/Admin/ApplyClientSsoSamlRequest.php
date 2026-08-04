<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ApplyClientSsoSamlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_id' => ['required', 'string', 'max:500', 'url'],
            'consumer_service_url' => ['required', 'string', 'max:500', 'url'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $entity = $this->input('entity_id');
        $acs = $this->input('consumer_service_url');

        $this->merge([
            'entity_id' => is_string($entity) ? trim($entity) : $entity,
            'consumer_service_url' => is_string($acs) ? rtrim(trim($acs), '/') : $acs,
        ]);
    }
}
