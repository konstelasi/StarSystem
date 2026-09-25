<?php

namespace Tests\Feature\Files;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MediaLibraryPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('admin.files'))->assertRedirect(route('login'));
    }

    public function test_the_page_shows_the_effective_upload_limit()
    {
        config(['files.max_size' => 1024 * 1024]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.files'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/files/Index')
                ->where('limits.maxFileBytes', 1024 * 1024)
                ->where('limits.maxFileSize', '1 MB')
                ->where('limits.bindingSetting', 'files.max_size')
                ->where('limits.canDetectTypes', true)
                ->etc()
            );
    }
}
