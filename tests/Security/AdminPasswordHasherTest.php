<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Admin\AdminPasswordHasher;
use PHPUnit\Framework\TestCase;

final class AdminPasswordHasherTest extends TestCase
{
    public function testItHashesAndVerifiesStrongPasswords(): void
    {
        $hasher = new AdminPasswordHasher();
        $hash = $hasher->hash('TwentyChoices2026!');

        self::assertNotSame('TwentyChoices2026!', $hash);
        self::assertTrue($hasher->verify('TwentyChoices2026!', $hash));
        self::assertFalse($hasher->verify('wrong-password', $hash));
    }

    public function testItRejectsWeakPasswords(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AdminPasswordHasher())->hash('short');
    }
}
