<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ExampleTest extends DuskTestCase
{
    /**
     * The application root renders the client login page (not a Laravel
     * welcome page), so assert the copy that page actually shows.
     */
    public function test_client_login_page_renders_at_root(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->assertPathIs('/')
                ->assertSee('Client Portal')
                ->assertSee('Sign in to manage your services')
                ->assertSee('Staff login');
        });
    }
}
