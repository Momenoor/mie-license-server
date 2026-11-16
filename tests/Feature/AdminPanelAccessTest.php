<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_user_may_use_the_admin_panel_on_a_live_server(): void
    {
        config(['app.env' => 'production']);

        $this->actingAs(User::factory()->create())->get('/admin')->assertOk();
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_sign_in(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
