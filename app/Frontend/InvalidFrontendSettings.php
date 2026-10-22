<?php

namespace App\Frontend;

use RuntimeException;

/**
 * An address table that can't be saved. The problems are sentences for the
 * admin, grouped under the position of the row they belong to.
 */
class InvalidFrontendSettings extends RuntimeException
{
    /**
     * @param  array<int, list<string>>  $problems  By row position.
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', array_merge(...array_values($problems))) ?: 'The addresses are not valid.');
    }
}
