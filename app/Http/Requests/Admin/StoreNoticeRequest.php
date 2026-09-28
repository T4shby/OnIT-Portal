<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesClientAccess;
use Illuminate\Foundation\Http\FormRequest;

class StoreNoticeRequest extends FormRequest
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
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
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
