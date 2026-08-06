@include('admin.partials.form-field', [
    'label' => 'SuperOps Account ID',
    'name' => 'superops_account_id',
    'value' => old('superops_account_id', $client->superops_account_id),
    'help' => $fieldHelps['superops_account_id'] ?? null,
])
@include('admin.partials.form-field', [
    'label' => 'SuperOps SSO enabled',
    'name' => 'superops_sso_enabled',
    'type' => 'checkbox',
    'value' => old('superops_sso_enabled', $client->superops_sso_enabled ?? true),
])
