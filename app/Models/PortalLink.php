<?php

namespace App\Models;

use App\Enums\PortalLinkType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'name',
        'description',
        'url',
        'link_type',
        'icon',
        'required_role',
        'display_order',
        'is_active',
        'open_in_new_tab',
    ];

    protected function casts(): array
    {
        return [
            'link_type' => PortalLinkType::class,
            'is_active' => 'boolean',
            'open_in_new_tab' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    protected function resolvedUrl(): Attribute
    {
        return Attribute::get(fn () => match ($this->link_type) {
            PortalLinkType::SuperOpsEmbedded => route('support.index'),
            PortalLinkType::SuperOpsSso => route('integrations.superops.launch'),
            PortalLinkType::Pax8Sso => route('integrations.pax8.launch'),
            default => $this->url,
        });
    }

    public static function urlForType(PortalLinkType $type): string
    {
        return match ($type) {
            PortalLinkType::SuperOpsEmbedded => route('support.index'),
            PortalLinkType::SuperOpsSso => route('integrations.superops.launch'),
            PortalLinkType::Pax8Sso => route('integrations.pax8.launch'),
            PortalLinkType::External => '',
        };
    }

    public function usesInternalRoute(): bool
    {
        return in_array($this->link_type, [
            PortalLinkType::SuperOpsEmbedded,
            PortalLinkType::SuperOpsSso,
            PortalLinkType::Pax8Sso,
        ], true);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isGlobal(): bool
    {
        return $this->client_id === null;
    }
}
