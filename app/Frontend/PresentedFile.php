<?php

namespace App\Frontend;

use App\Files\FileKind;

/**
 * A file as a theme sees it.
 */
final class PresentedFile
{
    public readonly bool $isImage;

    public function __construct(
        public readonly string $url,
        public readonly string $name,
        public readonly string $mime,
        public readonly string $alt = '',
    ) {
        $this->isImage = FileKind::of($mime) === FileKind::IMAGE;
    }
}
