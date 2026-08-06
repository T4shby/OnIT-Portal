@include('admin.partials.form-field', [
    'label' => 'Pax8 Company ID',
    'name' => 'pax8_company_id',
    'value' => old('pax8_company_id', $client->pax8_company_id),
    'help' => $fieldHelps['pax8_company_id'] ?? null,
])
@include('admin.partials.form-field', [
    'label' => 'Pax8 portal access enabled',
    'name' => 'pax8_sso_enabled',
    'type' => 'checkbox',
    'value' => old('pax8_sso_enabled', $client->pax8_sso_enabled ?? false),
])
