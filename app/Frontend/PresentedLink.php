<?php

namespace App\Frontend;

/**
 * An entry that a relation field links to, as a theme sees it. $url is null
 * when the linked entry's model has no address on the site.
 */
final class PresentedLink
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $url,
    ) {}
}
