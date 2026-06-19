<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class UpdateTeamMemberRequest extends StoreTeamMemberRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
        ];
    }
}
