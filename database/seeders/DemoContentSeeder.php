<?php

namespace Database\Seeders;

use App\Entries\EntryManager;
use App\Models\SchemaModel;
use App\Models\Site;
use App\Schema\FieldSpec;
use App\Schema\ModelSchema;
use App\Schema\OptionsSource;
use App\Schema\SchemaManager;
use App\Sites\CurrentSite;
use App\StarDust\TickRunner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sample models and entries for trying the admin by hand, written through
 * SchemaManager and EntryManager the same way the builder and the entry
 * form write them. A model whose slug already exists is skipped, so this
 * can be run again safely.
 *
 *   php artisan db:seed --class=DemoContentSeeder
 */
class DemoContentSeeder extends Seeder
{
    public function run(CurrentSite $current, SchemaManager $schema, EntryManager $entries, TickRunner $ticks): void
    {
        $current->set(Site::query()->findOrFail(config()->integer('starsystem.default_site')));

        foreach ([$this->articles(), $this->products(), $this->events()] as [$model, $rows]) {
            if ($schema->slugProblem($model->slug) !== null) {
                $this->command->warn("Skipped {$model->slug}, the slug is taken.");

                continue;
            }

            $result = $schema->createModel($model);

            if (! $result->succeeded()) {
                throw new RuntimeException("Creating {$model->slug} failed: {$result->error()}");
            }

            // Lets the filterable fields get their slots before the first
            // write. Under StarDust 0.3.0-alpha.1, on a fresh install with
            // no index page yet, an entry written first races the
            // promotion's backfill for a slot and every later tick fails.
            $this->drain($ticks);

            foreach ($rows as $row) {
                $entries->create($result->model, $this->validated($entries, $result->model, $row));
            }

            $this->command->info("Created {$model->slug} with ".count($rows).' entries.');
        }
    }

    private function drain(TickRunner $ticks): void
    {
        for ($i = 0; $i < 10; $i++) {
            $run = $ticks->run('manual', 10);

            if ($run->error !== null) {
                throw new RuntimeException("The tick failed: {$run->error}");
            }

            if ($run->stop_reason === 'idle' && $run->rounds <= 1) {
                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function validated(EntryManager $entries, SchemaModel $model, array $row): array
    {
        Validator::make($row, $entries->rulesFor($model))->validate();

        return $entries->normalizeFields($model, $row);
    }

    /**
     * @param  array<string, mixed>  $extra  Further FieldSpec arguments by name.
     */
    private function field(string $key, string $label, string $type, array $extra = []): FieldSpec
    {
        return new FieldSpec((string) Str::uuid(), $key, $label, $type, ...$extra);
    }

    /**
     * @param  array<string, string>  $options  value => label
     * @return array{options: array<string, mixed>}
     */
    private function options(array $options): array
    {
        $list = [];
        foreach ($options as $value => $label) {
            $list[] = ['value' => (string) $value, 'label' => $label];
        }

        return ['options' => OptionsSource::static($list)];
    }

    /**
     * @return array{ModelSchema, list<array<string, mixed>>}
     */
    private function articles(): array
    {
        $model = new ModelSchema('articles', 'Articles', icon: 'newspaper', group: 'Content', fields: [
            $this->field('title', 'Title', 'text', ['required' => true, 'filterable' => true, 'shownInList' => true]),
            $this->field('category', 'Category', 'select', ['settings' => $this->options([
                'news' => 'News',
                'guide' => 'Guide',
                'story' => 'Customer story',
            ]), 'filterable' => true, 'shownInList' => true]),
            $this->field('published_at', 'Published at', 'datetime', ['filterable' => true, 'shownInList' => true]),
            $this->field('featured', 'Featured', 'checkbox', ['filterable' => true]),
            $this->field('summary', 'Summary', 'textarea', ['helper' => 'One or two sentences for the list page.']),
            $this->field('body', 'Body', 'rich_text'),
            $this->field('tags', 'Tags', 'checkboxes', ['settings' => $this->options([
                'release' => 'Release',
                'hosting' => 'Hosting',
                'design' => 'Design',
                'community' => 'Community',
            ])]),
        ]);

        $titles = [
            ['StarSystem 0.1 is out', 'news', ['release']],
            ['Running a CMS on shared hosting without a shell', 'guide', ['hosting']],
            ['How the model builder previews a save', 'guide', ['design']],
            ['A batik shop in Solo moves its catalogue online', 'story', ['community']],
            ['Uploading a module from the admin', 'guide', ['hosting']],
            ['What changed in the media library', 'news', ['release', 'design']],
            ['A school newsletter built in an afternoon', 'story', ['community']],
            ['Choosing between a select and radio buttons', 'guide', ['design']],
            ['Updating your site from the admin panel', 'guide', ['release', 'hosting']],
            ['Meet-up notes from Yogyakarta', 'news', ['community']],
            ['A coffee roaster tracks its stockists', 'story', ['community']],
            ['Multi-site, one install', 'news', ['release', 'hosting']],
        ];

        $rows = [];
        foreach ($titles as $i => [$title, $category, $tags]) {
            $rows[] = [
                'title' => $title,
                'category' => $category,
                'published_at' => now()->subDays(3 * (count($titles) - $i))->setTime(9, 0)->format('Y-m-d H:i:s'),
                'featured' => $i % 4 === 0,
                'summary' => "A short summary of \"{$title}\" for the article list.",
                'body' => "<p>{$title}.</p><p>This is sample text for trying the entry form. Edit or delete it freely.</p>",
                'tags' => $tags,
            ];
        }

        return [$model, $rows];
    }

    /**
     * @return array{ModelSchema, list<array<string, mixed>>}
     */
    private function products(): array
    {
        $model = new ModelSchema('products', 'Products', icon: 'package', group: 'Shop', fields: [
            $this->field('name', 'Name', 'text', ['required' => true, 'filterable' => true, 'shownInList' => true]),
            $this->field('sku', 'SKU', 'text', ['filterable' => true, 'shownInList' => true]),
            $this->field('price', 'Price (IDR)', 'number', ['settings' => ['whole' => true, 'min' => 0], 'filterable' => true, 'shownInList' => true]),
            $this->field('stock', 'Stock', 'number', ['settings' => ['whole' => true, 'min' => 0], 'filterable' => true]),
            $this->field('status', 'Status', 'radio', ['settings' => $this->options([
                'draft' => 'Draft',
                'active' => 'Active',
                'archived' => 'Archived',
            ]), 'filterable' => true, 'shownInList' => true]),
            $this->field('colour', 'Colour', 'color'),
            $this->field('link', 'Product page', 'url'),
            $this->field('description', 'Description', 'textarea'),
        ]);

        $products = [
            ['Batik shirt, parang motif', 'BTK-001', 385000, 24, 'active', '#7a4b2a'],
            ['Batik shirt, kawung motif', 'BTK-002', 365000, 0, 'active', '#3b2f2f'],
            ['Tenun scarf', 'TNN-010', 210000, 12, 'active', '#b23a48'],
            ['Rattan tote bag', 'RTN-004', 295000, 8, 'active', '#c8a165'],
            ['Arabica beans, Gayo 250 g', 'KOP-101', 95000, 60, 'active', '#4e342e'],
            ['Arabica beans, Kintamani 250 g', 'KOP-102', 105000, 35, 'draft', '#5d4037'],
            ['Teak serving board', 'JTI-020', 175000, 5, 'active', '#a0522d'],
            ['Ceramic mug, Kasongan', 'KSG-007', 85000, 40, 'archived', '#d7ccc8'],
            ['Pandan soap bar', 'SBN-003', 35000, 120, 'active', '#6b8e23'],
            ['Wayang bookmark set', 'WYG-015', 45000, 0, 'draft', '#daa520'],
        ];

        $rows = [];
        foreach ($products as [$name, $sku, $price, $stock, $status, $colour]) {
            $rows[] = [
                'name' => $name,
                'sku' => $sku,
                'price' => $price,
                'stock' => $stock,
                'status' => $status,
                'colour' => $colour,
                'link' => 'https://example.com/products/'.Str::lower($sku),
                'description' => "Sample product. {$name}, handmade in small batches.",
            ];
        }

        return [$model, $rows];
    }

    /**
     * @return array{ModelSchema, list<array<string, mixed>>}
     */
    private function events(): array
    {
        $model = new ModelSchema('events', 'Events', icon: 'calendar', group: 'Content', fields: [
            $this->field('name', 'Name', 'text', ['required' => true, 'filterable' => true, 'shownInList' => true]),
            $this->field('starts_at', 'Starts at', 'datetime', ['filterable' => true, 'shownInList' => true]),
            $this->field('venue', 'Venue', 'text', ['shownInList' => true]),
            $this->field('capacity', 'Capacity', 'number', ['settings' => ['whole' => true, 'min' => 1]]),
            $this->field('contact', 'Contact email', 'email'),
            $this->field('online', 'Online', 'checkbox', ['filterable' => true]),
        ]);

        $events = [
            ['StarSystem meet-up', 14, 'Coworking space, Jakarta Selatan', 40, false],
            ['Shared hosting workshop', 21, 'Online', 100, true],
            ['Building your first model', 28, 'Online', 100, true],
            ['Craft makers market', 35, 'Pendopo, Yogyakarta', 250, false],
            ['Module authors call', 42, 'Online', 30, true],
            ['Year-end community dinner', 70, 'Restoran Sunda, Bandung', 60, false],
        ];

        $rows = [];
        foreach ($events as [$name, $inDays, $venue, $capacity, $online]) {
            $rows[] = [
                'name' => $name,
                'starts_at' => now()->addDays($inDays)->setTime(19, 0)->format('Y-m-d H:i:s'),
                'venue' => $venue,
                'capacity' => $capacity,
                'contact' => 'events@example.com',
                'online' => $online,
            ];
        }

        return [$model, $rows];
    }
}
