<?php

declare(strict_types=1);

namespace App\Core\Auth;

final class AuthenticationResult
{
    public function __construct(public readonly bool $success, public readonly string $message) {}
}
