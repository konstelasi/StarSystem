<?php

namespace Modules\Example;

/**
 * What the example module's action saw, for tests to read.
 */
class Activity
{
    /** @var list<array{entry: int, site: int|null}> */
    public static array $saved = [];
}
