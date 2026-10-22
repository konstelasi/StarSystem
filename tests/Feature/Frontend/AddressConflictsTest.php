<?php

namespace Tests\Feature\Frontend;

use App\Frontend\AddressConflicts;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AddressConflictsTest extends TestCase
{
    public function test_the_folders_and_prefixes_starsystem_keeps_are_taken()
    {
        foreach (['admin', 'api', 'install', 'settings', 'dashboard', '_system', '_modules', '_themes', 'files', 'build', 'storage', 'vendor', 'up'] as $path) {
            $this->assertTrue($this->conflicts()->uses($path), "{$path} should be taken");
        }
    }

    public function test_a_reserved_address_is_taken_whatever_its_case_or_slashes()
    {
        $this->assertTrue($this->conflicts()->uses('Admin'));
        $this->assertTrue($this->conflicts()->uses('/files/'));
    }

    public function test_an_address_with_a_route_of_its_own_is_taken()
    {
        $this->assertTrue($this->conflicts()->uses('login'));
        $this->assertTrue($this->conflicts()->uses('forgot-password'));
    }

    public function test_an_address_that_only_a_post_route_answers_is_taken()
    {
        Route::post('/send-something', fn () => 'ok');

        $this->assertTrue($this->conflicts()->uses('send-something'));
    }

    public function test_an_address_whose_route_takes_a_parameter_is_taken()
    {
        $this->assertTrue($this->conflicts()->uses('invitations'));
    }

    public function test_a_route_a_module_adds_later_is_taken()
    {
        $this->assertFalse($this->conflicts()->uses('shop'));

        Route::get('/shop', fn () => 'ok');

        $this->assertTrue($this->conflicts()->uses('shop'));
    }

    public function test_a_free_address_is_not_taken()
    {
        $this->assertFalse($this->conflicts()->uses('about'));
        $this->assertFalse($this->conflicts()->uses('blog'));
        $this->assertFalse($this->conflicts()->uses('hello-world'));
    }

    public function test_the_root_address_is_never_taken()
    {
        $this->assertFalse($this->conflicts()->uses(''));
        $this->assertFalse($this->conflicts()->uses('/'));
    }

    public function test_the_catch_all_route_does_not_make_every_address_taken()
    {
        Route::fallback(fn () => 'not found');

        $this->assertFalse($this->conflicts()->uses('about'));
        $this->assertTrue($this->conflicts()->uses('login'));
    }

    private function conflicts(): AddressConflicts
    {
        return $this->app->make(AddressConflicts::class);
    }
}
