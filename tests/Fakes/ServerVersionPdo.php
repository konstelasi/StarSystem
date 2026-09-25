<?php

namespace Tests\Fakes;

use PDO;

/**
 * A PDO that only reports a server version, for exercising StarDust's
 * version gate against servers the test suite doesn't have. It never
 * connects: the parent constructor is deliberately not called, and
 * StarDust's detector only reads ATTR_SERVER_VERSION.
 */
class ServerVersionPdo extends PDO
{
    public function __construct(private readonly string $version) {}

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_SERVER_VERSION ? $this->version : null;
    }
}
