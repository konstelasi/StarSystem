<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Security\Sessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database', 'session.lifetime' => 120]);
    }

    private function addSession(User $user, string $id, int $minutesAgo = 0, string $agent = 'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '10.0.0.'.strlen($id),
            'user_agent' => $agent,
            'payload' => '',
            'last_activity' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    private function sessions(): Sessions
    {
        return app(Sessions::class);
    }

    public function test_it_lists_the_live_sessions_newest_first_and_marks_this_one()
    {
        $user = User::factory()->create();
        $this->addSession($user, 'older', minutesAgo: 30);
        $this->addSession($user, 'current', minutesAgo: 1);

        $list = $this->sessions()->for($user, 'current');

        $this->assertCount(2, $list);
        $this->assertTrue($list[0]['current']);
        $this->assertFalse($list[1]['current']);
        $this->assertSame('Firefox', $list[0]['browser']);
        $this->assertSame('Linux', $list[0]['os']);
        $this->assertSame('10.0.0.7', $list[0]['ip']);
        $this->assertNotEmpty($list[0]['lastActive']);
    }

    public function test_a_session_past_its_lifetime_is_not_listed()
    {
        $user = User::factory()->create();
        $this->addSession($user, 'stale', minutesAgo: 121);
        $this->addSession($user, 'live', minutesAgo: 119);

        $this->assertCount(1, $this->sessions()->for($user, 'live'));
    }

    public function test_only_the_accounts_own_sessions_are_listed()
    {
        $user = User::factory()->create();
        $this->addSession($user, 'mine');
        $this->addSession(User::factory()->create(), 'theirs');

        $this->assertCount(1, $this->sessions()->for($user, 'mine'));
    }

    public function test_ending_the_others_keeps_this_one_and_leaves_other_accounts_alone()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->addSession($user, 'current');
        $this->addSession($user, 'phone');
        $this->addSession($user, 'laptop');
        $this->addSession($other, 'theirs');

        $this->assertSame(2, $this->sessions()->endOthers($user, 'current'));

        $this->assertEqualsCanonicalizing(['current', 'theirs'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_ending_with_no_session_to_keep_ends_them_all()
    {
        $user = User::factory()->create();
        $this->addSession($user, 'one');
        $this->addSession($user, 'two');

        $this->assertSame(2, $this->sessions()->endOthers($user));
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_it_does_nothing_when_sessions_are_not_kept_in_the_database()
    {
        config(['session.driver' => 'file']);
        $user = User::factory()->create();
        $this->addSession($user, 'one');

        $this->assertFalse($this->sessions()->available());
        $this->assertSame(0, $this->sessions()->endOthers($user));
        $this->assertSame(1, DB::table('sessions')->count());
    }
}
