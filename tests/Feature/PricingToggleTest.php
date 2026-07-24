<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The billing kill-switches: a master toggle and per-service toggles that force
 * a feature's cost to 0 (free) while preserving its stored price.
 */
class PricingToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_resolves_normally_by_default(): void
    {
        Setting::put('pricing_numerology', '15', 'pricing');

        $this->assertSame(15.0, Pricing::for('numerology'));
        $this->assertTrue(Pricing::billingEnabled());
        $this->assertTrue(Pricing::chargeEnabled('numerology'));
        $this->assertFalse(Pricing::isFree('numerology'));
    }

    public function test_master_switch_off_makes_everything_free(): void
    {
        Setting::put('pricing_numerology', '15', 'pricing');
        Setting::put('pricing_palmistry', '29', 'pricing');
        Setting::put('billing_enabled', '0', 'pricing');

        $this->assertSame(0.0, Pricing::for('numerology'));
        $this->assertSame(0.0, Pricing::for('palmistry'));
        $this->assertTrue(Pricing::isFree('numerology'));

        // The price itself is preserved — flipping billing back on restores it.
        Setting::put('billing_enabled', '1', 'pricing');
        $this->assertSame(15.0, Pricing::for('numerology'));
    }

    public function test_per_service_toggle_only_affects_that_service(): void
    {
        Setting::put('pricing_numerology', '15', 'pricing');
        Setting::put('pricing_palmistry', '29', 'pricing');

        // Turn OFF charging for numerology only.
        Setting::put('pricing_numerology_enabled', '0', 'pricing');

        $this->assertSame(0.0, Pricing::for('numerology'));   // free now
        $this->assertSame(29.0, Pricing::for('palmistry'));   // unaffected
        $this->assertFalse(Pricing::chargeEnabled('numerology'));
        $this->assertTrue(Pricing::chargeEnabled('palmistry'));
    }

    public function test_toggle_back_on_restores_charging(): void
    {
        Setting::put('pricing_numerology', '15', 'pricing');
        Setting::put('pricing_numerology_enabled', '0', 'pricing');
        $this->assertSame(0.0, Pricing::for('numerology'));

        Setting::put('pricing_numerology_enabled', '1', 'pricing');
        $this->assertSame(15.0, Pricing::for('numerology'));
    }

    public function test_negative_price_is_clamped_to_zero(): void
    {
        Setting::put('pricing_numerology', '-5', 'pricing');
        $this->assertSame(0.0, Pricing::for('numerology'));
    }
}
