<?php

namespace App\Services\Gdap;

use RuntimeException;

class GdapGraphException extends RuntimeException
{
    public function __construct(string $message, public int $statusCode = 0)
    {
        parent::__construct($message);
    }
}
