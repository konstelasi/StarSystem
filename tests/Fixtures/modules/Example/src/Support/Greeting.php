<?php

namespace Modules\Example\Support;

/**
 * Loaded only by the autoloading test, so it proves the module autoloader
 * found it rather than an earlier test.
 */
class Greeting
{
    public static function hello(): string
    {
        return 'Hello from the example module';
    }
}
