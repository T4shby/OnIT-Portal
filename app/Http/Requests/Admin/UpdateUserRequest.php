<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends StoreUserRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function () {
            /** @var \App\Models\User $user */
            $user = $this->route('user');
            $this->validateClientAccessForActor((int) $this->input('client_id'), $user);
        });
    }
}
