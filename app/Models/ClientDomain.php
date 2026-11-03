<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientDomain extends Model
{
    protected $fillable = [
        'client_id',
        'domain',
        'is_primary',
        'dkim_selectors',
        'source',
        'last_checked_at',
        'last_result',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'dkim_selectors' => 'array',
            'last_checked_at' => 'datetime',
            'last_result' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function dnsChecks(): HasMany
    {
        return $this->hasMany(ClientDomainDnsCheck::class)->orderByDesc('checked_at');
    }

    public function providerLabel(): ?string
    {
        $result = is_array($this->last_result) ? $this->last_result : [];
        $label = $result['provider']['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    public function overallBand(): string
    {
        if ($this->last_checked_at === null) {
            return 'pending';
        }

        $result = is_array($this->last_result) ? $this->last_result : [];
        $band = (string) ($result['overall'] ?? '');

        return in_array($band, ['green', 'yellow', 'red'], true) ? $band : 'pending';
    }

    public function hasRedChecks(): bool
    {
        // Unchecked / queued is not "red" - that false alarm made step 00 look broken.
        return $this->overallBand() === 'red';
    }
}
