<?php

namespace App\Frontend;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans rich text before a visitor gets it. Rich text is stored as the
 * HTML its author typed, so anything an Editor can type reaches the public
 * site unless it passes through here: scripts, event attributes such as
 * onclick, javascript: links and embedded frames are removed, and what is
 * left is formatting, links, images and tables.
 *
 * The rules are Symfony's HTML sanitizer, which parses the markup the way a
 * browser does, with a list of what to keep. They are not a pattern match.
 */
class HtmlCleaner
{
    /** The longest text cleaned, in characters. The sanitizer cuts off anything after it. */
    private const MOST_CHARACTERS = 1_000_000;

    /**
     * Wrappers that only group or style text. They are removed and what they
     * hold stays. Anything else that is not allowed is removed with its
     * content, which is the safe way to treat scripts, frames, forms and
     * whatever a browser might one day run.
     */
    private const UNWRAPPED = [
        'div', 'span', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'small', 'mark', 'abbr',
        'cite', 'address', 'time', 'kbd', 'var', 'samp', 'dl', 'dt', 'dd', 'details', 'summary', 'label', 'font', 'center',
    ];

    private ?HtmlSanitizer $sanitizer = null;

    public function clean(string $html): string
    {
        return ($this->sanitizer ??= new HtmlSanitizer($this->config()))->sanitize($html);
    }

    private function config(): HtmlSanitizerConfig
    {
        $config = new HtmlSanitizerConfig;

        foreach (['p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'sup', 'sub',
            'ul', 'li', 'blockquote', 'pre', 'code', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'caption'] as $element) {
            $config = $config->allowElement($element);
        }

        foreach (self::UNWRAPPED as $element) {
            $config = $config->blockElement($element);
        }

        return $config
            ->allowElement('ol', ['start'])
            ->allowElement('a', ['href', 'title'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])
            ->allowElement('th', ['colspan', 'rowspan', 'scope'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias()
            ->forceAttribute('a', 'rel', 'noopener')
            ->forceAttribute('img', 'loading', 'lazy')
            ->withMaxInputLength(self::MOST_CHARACTERS);
    }
}
