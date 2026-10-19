<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function overallBand(): string
    {
        $result = is_array($this->last_result) ? $this->last_result : [];
        $band = (string) ($result['overall'] ?? '');

        return in_array($band, ['green', 'yellow', 'red'], true) ? $band : 'red';
    }

    public function hasRedChecks(): bool
    {
        return $this->overallBand() === 'red' || $this->last_checked_at === null;
    }
}
