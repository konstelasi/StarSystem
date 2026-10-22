<?php

namespace App\Frontend;

use App\Models\SchemaModel;
use StarDust\Read\Entry;
use StarDust\Read\EntryPage;

/**
 * What an address on the public site leads to.
 *
 * A `home` has the root model's home entry, or none. An `entry` has the
 * entry, and a `list` has a page of entries and the cursor of the next
 * one. Anything else is `none`, which the front end answers with a 404.
 */
final class FrontendMatch
{
    public const HOME = 'home';

    public const ENTRY = 'entry';

    public const LIST = 'list';

    public const NONE = 'none';

    /**
     * @param  self::HOME|self::ENTRY|self::LIST|self::NONE  $kind
     * @param  string|null  $next  The cursor of the next page of a list, if there is one.
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?FrontendRoute $route = null,
        public readonly ?SchemaModel $model = null,
        public readonly ?Entry $entry = null,
        public readonly ?EntryPage $page = null,
        public readonly ?string $next = null,
    ) {}

    public static function none(): self
    {
        return new self(self::NONE);
    }

    public function found(): bool
    {
        return $this->kind !== self::NONE;
    }
}
