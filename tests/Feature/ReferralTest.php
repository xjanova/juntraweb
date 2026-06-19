<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    // The redirect target ('/') reads reference tables, so migrate the schema.
    use RefreshDatabase;

    public function test_referral_link_redirects_home_and_captures_code(): void
    {
        $response = $this->get('/r/ABC123');

        $response->assertRedirect(route('home'));
        $response->assertCookie('juntra_ref', 'ABC123');
    }

    public function test_referral_code_is_sanitized(): void
    {
        // A dotted/slashed code can't reach the controller (route constraint is
        // [A-Za-z0-9_-]+), but underscores/dashes are valid and preserved.
        $response = $this->get('/r/ref_code-9');

        $response->assertRedirect(route('home'));
        $response->assertCookie('juntra_ref', 'ref_code-9');
    }

    public function test_referral_rejects_codes_with_illegal_characters(): void
    {
        // '.' is outside the route constraint → no match → 404, never reaches
        // the controller.
        $this->get('/r/bad.code')->assertNotFound();
    }
}
