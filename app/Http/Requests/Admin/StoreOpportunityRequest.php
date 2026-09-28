<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesClientAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpportunityRequest extends FormRequest
{
    use ValidatesClientAccess;

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

    /**
     * client_id is taken from the request - the controller's authorize('create'|'update')
     * only proves the actor is staff (and, on update, may touch the record's *current*
     * client). Also require access to the submitted client so staff cannot create or
     * move content into a client they are not assigned to.
     */
    public function withValidator($validator): void
    {
        $validator->after(function () {
            $this->validateClientAccessForActor((int) $this->input('client_id'));
        });
    }
}
