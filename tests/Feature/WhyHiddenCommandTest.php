<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use Illuminate\Support\Facades\Artisan;

class WhyHiddenCommandTest extends CheckoutTest
{
    /**
     * Captured output, rather than expectsOutputToContain — the command styles
     * its lines, and asserting on the finished text is what a person actually
     * reads.
     *
     * @param  array<string, mixed>  $args
     */
    private function diagnose(array $args): string
    {
        Artisan::call('nexmile:why-hidden', $args);

        return Artisan::output();
    }

    public function test_it_names_kyc_as_the_reason_a_new_restaurant_is_invisible(): void
    {
        $shop = $this->restaurant();
        $shop->update(['kyc_status' => KycStatus::Pending]);

        // The usual answer, and the one nobody guesses from an empty list.
        $output = $this->diagnose(['merchant' => $shop->id]);

        $this->assertStringContainsString('KYC verified', $output);
        $this->assertStringContainsString('an admin must verify it', $output);
        $this->assertStringContainsString('Not in the nearby list', $output);
    }

    public function test_it_names_missing_coordinates(): void
    {
        $shop = $this->restaurant();
        $shop->forceFill(['latitude' => null, 'longitude' => null])->save();

        $output = $this->diagnose(['merchant' => $shop->id]);

        $this->assertStringContainsString('Has coordinates', $output);
        $this->assertStringContainsString('not set', $output);
    }

    public function test_it_measures_the_distance_when_given_a_customer_point(): void
    {
        $shop = $this->restaurant();

        // ~2.2 km north of the restaurant — outside the 1 km promise.
        $output = $this->diagnose(['merchant' => $shop->id, '--lat' => '9.9395', '--lng' => '78.1193']);

        $this->assertStringContainsString('Within the delivery radius', $output);
        $this->assertStringContainsString('too far', $output);
    }

    public function test_a_verified_nearby_restaurant_reports_as_visible(): void
    {
        $shop = $this->restaurant();
        $this->dish($shop);

        $output = $this->diagnose(['merchant' => $shop->id, '--lat' => '9.9200', '--lng' => '78.1195']);

        $this->assertStringContainsString('Visible and open', $output);
    }

    public function test_it_separates_being_hidden_from_being_closed(): void
    {
        $shop = $this->restaurant();
        $this->dish($shop);
        $shop->update(['is_accepting_orders' => false]);

        // Still listed — closed shops rank below open ones rather than
        // vanishing — so the message must not claim it is hidden.
        $output = $this->diagnose(['merchant' => $shop->id]);

        $this->assertStringContainsString('shown as closed', $output);
        $this->assertStringNotContainsString('Not in the nearby list', $output);
    }

    public function test_it_finds_a_merchant_by_name_or_email(): void
    {
        $shop = $this->restaurant();

        $this->artisan('nexmile:why-hidden', ['merchant' => 'Ponnusamy'])->assertSuccessful();
        $this->artisan('nexmile:why-hidden', ['merchant' => $shop->user->email])->assertSuccessful();
        $this->artisan('nexmile:why-hidden', ['merchant' => 'no such shop'])->assertFailed();
    }
}
