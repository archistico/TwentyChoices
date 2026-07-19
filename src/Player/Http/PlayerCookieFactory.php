<?php

declare(strict_types=1);

namespace App\Player\Http;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Cookie;

final class PlayerCookieFactory
{
    public const NAME = 'twenty_choices_player';

    public function create(string $rawToken, bool $secure): Cookie
    {
        return Cookie::create(self::NAME)
            ->withValue($rawToken)
            ->withExpires(new DateTimeImmutable('+1 year'))
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}
