<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Root path redirects to login (guest) or dashboard (auth). Lock 1: root is minimal.
     */
    public function test_platform_root_redirects_to_platform_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
