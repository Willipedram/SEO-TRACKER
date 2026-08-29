<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Security\Csrf;
use Tests\TestCase;

final class CsrfTest extends TestCase
{
    public function testTokenIsStableAndValidatedWithConstantTimeComparison(): void
    {
        $_SESSION = [];
        $token = Csrf::token();
        $this->assertSame(64, strlen($token));
        $this->assertSame($token, Csrf::token());
        $this->assertTrue(Csrf::valid($token));
        $this->assertTrue(!Csrf::valid(str_repeat('0', 64)));
    }

    public function testRotationInvalidatesThePreviousToken(): void
    {
        $_SESSION = [];
        $old = Csrf::token();
        $new = Csrf::rotate();
        $this->assertTrue($old !== $new);
        $this->assertTrue(!Csrf::valid($old));
        $this->assertTrue(Csrf::valid($new));
    }
}
