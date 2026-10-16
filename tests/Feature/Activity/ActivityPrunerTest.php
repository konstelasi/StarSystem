<?php

namespace Tests\Feature\Activity;

use App\Activity\ActivityAction;
use App\Activity\ActivityPruner;
use App\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class ActivityPrunerTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
        config(['starsystem.activity.keep_days' => 30]);
    }

    private function row(int $daysAgo, string $label = 'row'): Activity
    {
        $row = new Activity(['action' => ActivityAction::LOGIN, 'subject_label' => $label]);
        $row->forceFill(['created_at' => CarbonImmutable::now()->subDays($daysAgo)])->save();

        return $row;
    }

    public function test_rows_older_than_the_setting_are_deleted_and_newer_ones_stay()
    {
        $this->row(31, 'old');
        $this->row(29, 'recent');
        $this->row(0, 'new');

        $this->assertSame(1, app(ActivityPruner::class)->prune());

        $this->assertSame(['new', 'recent'], Activity::query()->orderByDesc('id')->pluck('subject_label')->all());
    }

    public function test_every_sites_rows_are_pruned()
    {
        $other = $this->makeSite('other.example.com');
        $this->row(40);
        $this->asSite($other);
        $this->row(40);
        $this->row(1);

        $this->assertSame(2, app(ActivityPruner::class)->prune());

        $this->assertSame(1, Activity::withoutGlobalScopes()->count());
    }

    public function test_zero_keeps_everything()
    {
        config(['starsystem.activity.keep_days' => 0]);
        $this->row(4000);

        $this->assertSame(0, app(ActivityPruner::class)->prune());
        $this->assertSame(1, Activity::query()->count());
    }

    public function test_one_run_stops_at_its_limit_and_the_next_one_carries_on()
    {
        foreach (range(1, 5) as $i) {
            $this->row(40);
        }

        $this->assertSame(3, app(ActivityPruner::class)->prune(3));
        $this->assertSame(2, Activity::query()->count());

        $this->assertSame(2, app(ActivityPruner::class)->prune(3));
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_the_command_reports_what_it_deleted()
    {
        $this->row(40);

        $this->artisan('activity:prune')->expectsOutputToContain('Deleted 1 activity row.')->assertSuccessful();
        $this->artisan('activity:prune')->expectsOutputToContain('Deleted 0 activity rows.')->assertSuccessful();
    }

    public function test_the_prune_is_scheduled_daily()
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => $event->description === 'activity:prune');

        $this->assertCount(1, $events);
        $this->assertSame('47 3 * * *', $events->first()->expression);
    }
}
