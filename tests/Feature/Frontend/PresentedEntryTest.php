<?php

namespace Tests\Feature\Frontend;

use App\Frontend\HtmlCleaner;
use App\Frontend\PresentedEntry;
use App\Frontend\PresentedFile;
use App\Frontend\PresentedLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

/**
 * A PresentedEntry is built by hand here, so nothing touches the database.
 * EntryPresenterTest covers how a real entry becomes one.
 */
class PresentedEntryTest extends TestCase
{
    private const UUID = '0b4a3d4e-7f1b-4c47-9f6e-1c2d3e4f5a01';

    private const OTHER = '0b4a3d4e-7f1b-4c47-9f6e-1c2d3e4f5a02';

    public function test_text_returns_plain_text_that_blade_will_escape()
    {
        $entry = $this->entry(['note' => $this->field('text', '<script>alert(1)</script> & "quotes"')]);

        $this->assertSame('<script>alert(1)</script> & "quotes"', $entry->text('note'));
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;quotes&quot;',
            Blade::render('{{ $entry->text("note") }}', ['entry' => $entry]),
        );
    }

    public function test_text_is_empty_for_a_field_that_is_missing_or_empty()
    {
        $entry = $this->entry(['note' => $this->field('text', null)]);

        $this->assertSame('', $entry->text('note'));
        $this->assertSame('', $entry->text('nothing'));
        $this->assertNull($entry->raw('nothing'));
    }

    public function test_text_shows_the_label_of_a_choice_and_joins_several_values()
    {
        $entry = $this->entry([
            'kind' => $this->field('select', 'a', labels: ['a' => 'Alpha', 'b' => 'Beta']),
            'tags' => $this->field('checkboxes', ['a', 'b', 'c'], labels: ['a' => 'Alpha', 'b' => 'Beta']),
            'count' => $this->field('number', 0),
        ]);

        $this->assertSame('Alpha', $entry->text('kind'));
        $this->assertSame('Alpha, Beta, c', $entry->text('tags'));
        $this->assertSame('0', $entry->text('count'));
    }

    public function test_text_of_a_file_or_a_relation_is_the_names()
    {
        $entry = $this->entry(
            [
                'cover' => $this->field('file', [self::UUID, self::OTHER]),
                'author' => $this->field('relation', [4, 5]),
            ],
            files: [self::UUID => new PresentedFile('/f/a', 'a.png', 'image/png'), self::OTHER => new PresentedFile('/f/b', 'b.pdf', 'application/pdf')],
            links: ['author' => [new PresentedLink(4, 'Ayu', '/team/ayu'), new PresentedLink(5, 'Budi', null)]],
        );

        $this->assertSame('a.png, b.pdf', $entry->text('cover'));
        $this->assertSame('Ayu, Budi', $entry->text('author'));
    }

    public function test_html_on_a_non_rich_field_is_escaped()
    {
        $entry = $this->entry(['note' => $this->field('textarea', '<b onclick="x()">bold</b> & more')]);

        $html = $entry->html('note');

        $this->assertInstanceOf(HtmlString::class, $html);
        $this->assertSame('&lt;b onclick=&quot;x()&quot;&gt;bold&lt;/b&gt; &amp; more', (string) $html);
    }

    public function test_html_on_rich_text_removes_scripts_and_keeps_formatting()
    {
        $entry = $this->entry(['body' => $this->field('rich_text', '<p onclick="x()">Hi <strong>there</strong></p><script>alert(1)</script><a href="javascript:alert(1)">bad</a>')]);

        $html = (string) $entry->html('body');

        $this->assertStringContainsString('<p>Hi <strong>there</strong></p>', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript', $html);
        $this->assertSame($html, Blade::render('{!! $entry->html("body") !!}', ['entry' => $entry]));
    }

    public function test_html_of_a_missing_rich_text_field_is_empty()
    {
        $this->assertSame('', (string) $this->entry(['body' => $this->field('rich_text', null)])->html('body'));
        $this->assertSame('', (string) $this->entry([])->html('body'));
    }

    public function test_has_is_false_for_nothing_and_for_a_box_not_ticked()
    {
        $entry = $this->entry([
            'empty' => $this->field('text', ''),
            'none' => $this->field('text', null),
            'list' => $this->field('checkboxes', []),
            'text' => $this->field('text', 'x'),
            'zero' => $this->field('number', 0),
            'ticked' => $this->field('checkbox', 1),
            'unticked' => $this->field('checkbox', 0),
        ]);

        foreach (['empty', 'none', 'list', 'unticked', 'missing'] as $key) {
            $this->assertFalse($entry->has($key), "{$key} has nothing to show");
        }

        foreach (['text', 'zero', 'ticked'] as $key) {
            $this->assertTrue($entry->has($key), "{$key} has something to show");
        }
    }

    public function test_date_writes_a_stored_date_in_the_given_format()
    {
        $entry = $this->entry([
            'when' => $this->field('datetime', '2026-10-22 17:30:00'),
            'day' => $this->field('datetime', '2026-01-05 00:00:00'),
            'text' => $this->field('text', 'tomorrow'),
            'blank' => $this->field('datetime', null),
        ]);

        $this->assertSame('22 October 2026', $entry->date('when'));
        $this->assertSame('2026-10-22 17:30', $entry->date('when', 'Y-m-d H:i'));
        $this->assertSame('5 January 2026', $entry->date('day'));
        $this->assertSame('', $entry->date('text'), 'Words that look like a date are not read as one.');
        $this->assertSame('', $entry->date('blank'));
        $this->assertSame('', $entry->date('missing'));
    }

    public function test_date_uses_the_language_of_the_site()
    {
        $entry = $this->entry(['when' => $this->field('datetime', '2026-10-22 17:30:00')], locale: 'id');

        $this->assertSame('22 Oktober 2026', $entry->date('when'));
    }

    public function test_date_with_a_locale_nobody_knows_still_gives_a_date()
    {
        $entry = $this->entry(['when' => $this->field('datetime', '2026-10-22 17:30:00')], locale: 'zz-nowhere');

        $this->assertMatchesRegularExpression('/^22 \w+ 2026$/', $entry->date('when'));
    }

    public function test_file_and_files_follow_the_stored_order_and_skip_what_is_gone()
    {
        $a = new PresentedFile('/f/a', 'a.png', 'image/png');
        $b = new PresentedFile('/f/b', 'b.pdf', 'application/pdf');
        $entry = $this->entry(
            [
                'one' => $this->field('file', strtoupper(self::OTHER)),
                'many' => $this->field('file', [self::OTHER, '0b4a3d4e-7f1b-4c47-9f6e-ffffffffffff', self::UUID]),
                'gone' => $this->field('file', '0b4a3d4e-7f1b-4c47-9f6e-ffffffffffff'),
                'empty' => $this->field('file', null),
            ],
            files: [self::UUID => $a, self::OTHER => $b],
        );

        $this->assertSame($b, $entry->file('one'), 'A uuid matches whatever its case.');
        $this->assertSame([$b, $a], $entry->files('many'));
        $this->assertSame($b, $entry->file('many'));
        $this->assertNull($entry->file('gone'));
        $this->assertSame([], $entry->files('gone'));
        $this->assertNull($entry->file('empty'));
        $this->assertNull($entry->file('missing'));
    }

    public function test_a_presented_file_knows_whether_it_is_an_image()
    {
        $this->assertTrue((new PresentedFile('/f/a', 'a.png', 'image/png'))->isImage);
        $this->assertTrue((new PresentedFile('/f/a', 'a.svg', 'image/svg+xml'))->isImage);
        $this->assertFalse((new PresentedFile('/f/a', 'a.pdf', 'application/pdf'))->isImage);
        $this->assertSame('', (new PresentedFile('/f/a', 'a.pdf', 'application/pdf'))->alt);
    }

    public function test_link_and_links_give_the_entries_a_relation_field_names()
    {
        $ayu = new PresentedLink(4, 'Ayu', '/team/ayu');
        $budi = new PresentedLink(5, 'Budi', null);
        $entry = $this->entry(['author' => $this->field('relation', [4, 5]), 'editor' => $this->field('relation', null)], links: ['author' => [$ayu, $budi]]);

        $this->assertSame([$ayu, $budi], $entry->links('author'));
        $this->assertSame($ayu, $entry->link('author'));
        $this->assertNull($entry->link('editor'));
        $this->assertSame([], $entry->links('missing'));
    }

    public function test_fields_lists_the_rest_in_order_and_leaves_out_the_title_the_address_and_hidden_fields()
    {
        $entry = $this->entry(
            [
                'title' => $this->field('text', 'Hello', 'Title'),
                'slug' => $this->field('text', 'hello', 'Slug'),
                'body' => $this->field('rich_text', '<p>x</p>', 'Body'),
                'secret' => $this->field('hidden', 'internal', 'Secret'),
                'when' => $this->field('datetime', null, 'When'),
            ],
            leave: ['title', 'slug'],
        );

        $this->assertSame([
            ['key' => 'body', 'label' => 'Body', 'type' => 'rich_text', 'value' => '<p>x</p>'],
            ['key' => 'when', 'label' => 'When', 'type' => 'datetime', 'value' => null],
        ], $entry->fields());

        $this->assertSame('internal', $entry->raw('secret'), 'A theme can still ask for a hidden field by key.');
    }

    public function test_the_basics_are_properties()
    {
        $entry = $this->entry([]);

        $this->assertSame(7, $entry->id);
        $this->assertSame('https://site.test/blog/hello', $entry->url);
        $this->assertSame('Hello', $entry->title);
        $this->assertSame('2026-10-22', $entry->createdAt->toDateString());
    }

    /**
     * @param  array<string, array{label: string, type: string, value: mixed, labels: array<string, string>}>  $fields
     * @param  list<string>  $leave
     * @param  array<string, PresentedFile>  $files
     * @param  array<string, list<PresentedLink>>  $links
     */
    private function entry(array $fields, array $leave = [], array $files = [], array $links = [], string $locale = 'en'): PresentedEntry
    {
        return new PresentedEntry(
            id: 7,
            url: 'https://site.test/blog/hello',
            title: 'Hello',
            createdAt: CarbonImmutable::parse('2026-10-22 10:00:00'),
            fields: $fields,
            cleaner: new HtmlCleaner,
            leave: $leave,
            files: $files,
            links: $links,
            locale: $locale,
        );
    }

    /**
     * @param  array<string, string>  $labels
     * @return array{label: string, type: string, value: mixed, labels: array<string, string>}
     */
    private function field(string $type, mixed $value, string $label = 'Field', array $labels = []): array
    {
        return ['label' => $label, 'type' => $type, 'value' => $value, 'labels' => $labels];
    }
}
