<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Waaseyaa\OAuthProvider\SessionInterface;

final class NativeSessionAdapter implements SessionInterface
{
    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }
}
