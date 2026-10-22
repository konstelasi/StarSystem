<?php

namespace Tests\Unit\Frontend;

use App\Frontend\HtmlCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlCleanerTest extends TestCase
{
    public function test_html_removes_script_tags_and_event_attributes()
    {
        $clean = (new HtmlCleaner)->clean('<p onclick="steal()" onmouseover="x()">Hi <script>alert(1)</script><strong>there</strong></p><img src="/a.png" onerror="alert(1)" alt="A">');

        $this->assertStringContainsString('<p>Hi <strong>there</strong></p>', $clean);
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('alert', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringContainsString('<img src="/a.png" alt="A"', $clean);
    }

    #[DataProvider('scriptLinks')]
    public function test_html_removes_javascript_links(string $href)
    {
        $clean = (new HtmlCleaner)->clean('<a href="'.$href.'">click</a>');

        $this->assertStringNotContainsString('href', $clean);
        $this->assertStringContainsString('click', $clean);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function scriptLinks(): array
    {
        return [
            'plain' => ['javascript:alert(1)'],
            'mixed case' => ['JaVaScRiPt:alert(1)'],
            'an entity' => ['&#106;avascript:alert(1)'],
            'a vbscript link' => ['vbscript:msgbox(1)'],
            'a data page' => ['data:text/html,<script>alert(1)</script>'],
        ];
    }

    public function test_html_removes_script_addresses_from_images()
    {
        $clean = (new HtmlCleaner)->clean('<img src="javascript:alert(1)"><img src="data:image/png;base64,AAAA">');

        $this->assertStringNotContainsString('src', $clean);
    }

    public function test_html_removes_what_a_browser_could_run_or_post()
    {
        foreach ([
            '<style>p{color:red}</style>' => 'color',
            '<iframe src="https://evil.test"></iframe>' => 'evil.test',
            '<object data="x.swf"></object>' => 'x.swf',
            '<embed src="x.swf">' => 'x.swf',
            '<form action="/x"><input name="a"><button>go</button></form>' => 'form',
            '<svg onload="alert(1)"><circle/></svg>' => 'svg',
            '<math><mi>y</mi></math>' => 'math',
            '<base href="https://evil.test/">' => 'evil.test',
            '<meta http-equiv="refresh" content="0;url=https://evil.test">' => 'evil.test',
            '<p style="position:fixed" class="x" id="y">text</p>' => 'position',
        ] as $html => $gone) {
            $this->assertStringNotContainsString($gone, (new HtmlCleaner)->clean('<p>kept</p>'.$html), "{$html} should be removed");
        }
    }

    public function test_html_keeps_the_tags_the_editor_produces()
    {
        $html = '<h1>One</h1><h2>Two</h2><h3>Three</h3><h4>Four</h4><h5>Five</h5><h6>Six</h6>'
            .'<p>A <strong>strong</strong>, <b>bold</b>, <em>em</em>, <i>italic</i>, <u>under</u>, <s>strike</s>, <del>del</del>, x<sup>2</sup> and H<sub>2</sub>O<br>break</p>'
            .'<ul><li>one</li><li>two</li></ul><ol start="3"><li>three</li></ol>'
            .'<blockquote>quote</blockquote><pre><code>a &lt; b</code></pre><hr>'
            .'<figure><img src="https://x.test/a.png" alt="A" title="T" width="10" height="20"><figcaption>cap</figcaption></figure>'
            .'<table><caption>c</caption><thead><tr><th scope="col" colspan="2">H</th></tr></thead><tbody><tr><td rowspan="2">c</td></tr></tbody><tfoot><tr><td>f</td></tr></tfoot></table>';

        $clean = (new HtmlCleaner)->clean($html);

        foreach (['<h1>One</h1>', '<h6>Six</h6>', '<strong>strong</strong>', '<b>bold</b>', '<em>em</em>', '<i>italic</i>', '<u>under</u>', '<s>strike</s>',
            '<del>del</del>', '<sup>2</sup>', '<sub>2</sub>', '<br />', '<ul><li>one</li><li>two</li></ul>', '<ol start="3"><li>three</li></ol>',
            '<blockquote>quote</blockquote>', '<pre><code>a &lt; b</code></pre>', '<hr />', '<figcaption>cap</figcaption>', 'width="10"', 'height="20"',
            'title="T"', '<caption>c</caption>', '<th scope="col" colspan="2">H</th>', '<td rowspan="2">c</td>', '<tfoot><tr><td>f</td></tr></tfoot>'] as $kept) {
            $this->assertStringContainsString($kept, $clean, "{$kept} should be kept");
        }
    }

    public function test_html_keeps_the_text_inside_wrappers_that_only_group_it()
    {
        $this->assertSame('<p>a</p>bc d', (new HtmlCleaner)->clean('<p>a</p><div>b</div><span class="x">c</span> <section>d</section>'));
    }

    public function test_html_keeps_safe_links_and_makes_them_noopener()
    {
        $clean = (new HtmlCleaner)->clean('<a href="/about" title="A" target="_blank">r</a> <a href="https://x.test/?a=1&amp;b=2">e</a> <a href="mailto:a@b.test">m</a> <a href="tel:+6221">t</a>');

        $this->assertStringContainsString('<a href="/about" title="A" rel="noopener">r</a>', $clean);
        // The sanitizer writes "=" in an attribute as an entity, which a browser reads back as "=".
        $this->assertStringContainsString('href="https://x.test/?a&#61;1&amp;b&#61;2" rel="noopener">e</a>', $clean);
        $this->assertStringContainsString('href="mailto:a&#64;b.test"', $clean);
        $this->assertStringContainsString('href="tel:&#43;6221"', $clean);
        $this->assertStringNotContainsString('target', $clean);
        $this->assertSame(4, substr_count($clean, 'rel="noopener"'));
    }

    public function test_html_loads_images_lazily()
    {
        $this->assertStringContainsString('loading="lazy"', (new HtmlCleaner)->clean('<img src="/a.png" alt="">'));
    }

    public function test_html_with_no_markup_is_returned_as_text()
    {
        $this->assertSame('Just words &amp; more', (new HtmlCleaner)->clean('Just words & more'));
        $this->assertSame('', (new HtmlCleaner)->clean(''));
    }
}
