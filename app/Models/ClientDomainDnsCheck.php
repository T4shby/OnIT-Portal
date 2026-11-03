<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientDomainDnsCheck extends Model
{
    protected $fillable = [
        'client_id',
        'client_domain_id',
        'checked_by_user_id',
        'source',
        'overall',
        'overall_extended',
        'provider_key',
        'result',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(ClientDomain::class, 'client_domain_id');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }
}
