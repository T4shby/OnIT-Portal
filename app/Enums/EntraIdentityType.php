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

    /** Value written to extensionAttribute1 for SuperOps SCIM expression mapping only. */
    public function superOpsNameHint(): string
    {
        return $this->displaySuffix();
    }
}
