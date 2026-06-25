<?php

namespace App\Enums;

enum EntraIdentityType: string
{
    case User = 'user';
    case SharedMailbox = 'shared_mailbox';

    public function displaySuffix(): string
    {
        return match ($this) {
            self::User => 'User Mailbox',
            self::SharedMailbox => 'Shared Mailbox',
        };
    }

    /** Suffix hint written to extensionAttribute1 — Entra SCIM appends this to last name in SuperOps. */
    public function superOpsNameHint(): string
    {
        return $this->displaySuffix();
    }
}
