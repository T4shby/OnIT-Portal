<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientOpportunity extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'title',
        'body',
        'category',
        'status',
        'is_active',
        'display_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'open' => 'info',
            'in_progress' => 'warning',
            'won' => 'success',
            'lost' => 'danger',
            default => 'default',
        };
    }
}
