<?php

namespace Tests\Feature;

use App\Enums\FulfilmentType;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\Reviews\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ratings (EP12) and favourites.
 *
 * The customer app shows a rating badge on every restaurant card, so the
 * number behind it decides which kitchens get orders. It has to be earned from
 * real delivered orders, and it has to be hard to game.
 */
class ReviewAndFavouriteTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Customer '.$n,
            'phone' => '90000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "customer{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }

    private function restaurant(): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n,
            'phone' => '91000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "owner{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $owner->id,
            'business_name' => 'Ponnusamy Hotel '.$n,
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'latitude' => 9.9195,
            'longitude' => 78.1193,
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
        ]);
    }

    private function order(User $customer, Merchant $merchant, OrderStatus $status = OrderStatus::Delivered): Order
    {
        static $n = 0;
        $n++;

        return $merchant->orders()->create([
            'order_number' => 'NXR'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'user_id' => $customer->id,
            'status' => $status,
            'fulfilment_type' => FulfilmentType::Delivery,
            'delivery_contact_name' => 'Meena',
            'delivery_line1' => '4 Gandhi Nagar',
            'delivery_city' => 'Madurai',
            'delivery_pincode' => '625020',
            'items_total' => 300,
            'grand_total' => 340,
            'merchant_payout' => 270,
            'placed_at' => now(),
        ]);
    }

    public function test_a_customer_can_rate_a_delivered_order(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 5,
            'comment' => 'Hot and on time.',
        ])->assertCreated()->assertJsonPath('data.rating', 5);

        $this->getJson("/api/v1/orders/{$order->id}/review")
            ->assertOk()
            ->assertJsonPath('data.rating', 5);
    }

    public function test_an_unrated_order_reports_null_rather_than_a_score(): void
    {
        // So the app can tell "not rated yet" from "rated 0", which are
        // different screens.
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        Sanctum::actingAs($customer);

        $this->getJson("/api/v1/orders/{$order->id}/review")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_an_order_that_never_arrived_cannot_be_rated(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant(), OrderStatus::Preparing);

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => 1])
            ->assertStatus(422);
    }

    public function test_an_order_cannot_be_rated_twice(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, $this->restaurant());

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => 5])->assertCreated();
        $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => 1])->assertStatus(422);
    }

    public function test_someone_elses_order_cannot_be_rated(): void
    {
        // Otherwise a rating is one order id away, and a competitor can walk
        // the range.
        $order = $this->order($this->customer(), $this->restaurant());

        Sanctum::actingAs($this->customer());

        $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => 1])
            ->assertNotFound();
    }

    public function test_a_rating_is_withheld_until_enough_people_have_left_one(): void
    {
        /*
         * One five-star review from the owner's cousin is not a rating, and a
         * single bad night should not brand a new kitchen 1.0 for good. The
         * app hides the badge while this is null.
         */
        $merchant = $this->restaurant();

        foreach ([5, 4] as $score) {
            $customer = $this->customer();
            Sanctum::actingAs($customer);
            $order = $this->order($customer, $merchant);
            $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => $score])->assertCreated();
        }

        $this->assertNull($merchant->fresh()->rating);
        $this->assertSame(2, $merchant->fresh()->rating_count);

        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $order = $this->order($customer, $merchant);
        $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => 3])->assertCreated();

        // (5 + 4 + 3) / 3
        $this->assertSame(4.0, $merchant->fresh()->rating);
        $this->assertSame(3, $merchant->fresh()->rating_count);
    }

    public function test_the_aggregate_is_recomputed_not_nudged(): void
    {
        /*
         * An incremental average drifts silently the moment a review is
         * removed. Recalculating from the table is the only version that
         * survives moderation.
         */
        $merchant = $this->restaurant();

        foreach ([5, 5, 5, 1] as $score) {
            $customer = $this->customer();
            Sanctum::actingAs($customer);
            $order = $this->order($customer, $merchant);
            $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => $score]);
        }

        $merchant->reviews()->where('rating', 1)->delete();
        app(ReviewService::class)->recalculate($merchant);

        $this->assertSame(5.0, $merchant->fresh()->rating);
        $this->assertSame(3, $merchant->fresh()->rating_count);
    }

    public function test_bookmarking_is_idempotent_and_reversible(): void
    {
        $customer = $this->customer();
        $merchant = $this->restaurant();

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/restaurants/{$merchant->id}/favourite")->assertOk();
        $this->postJson("/api/v1/restaurants/{$merchant->id}/favourite")->assertOk();

        // Two taps, one row — the unique index would have thrown otherwise.
        $this->assertSame(1, $customer->favourites()->count());

        $this->getJson('/api/v1/favourites')
            ->assertOk()
            ->assertJsonPath('data.0.id', $merchant->id)
            ->assertJsonPath('data.0.is_favourite', true);

        $this->deleteJson("/api/v1/restaurants/{$merchant->id}/favourite")->assertOk();
        $this->assertSame(0, $customer->fresh()->favourites()->count());
    }

    public function test_an_unverified_restaurant_cannot_be_bookmarked(): void
    {
        // Same gate as discovery: a customer cannot bookmark a shop they are
        // not allowed to see in the first place.
        $merchant = $this->restaurant();
        $merchant->forceFill(['kyc_status' => KycStatus::Pending])->save();

        Sanctum::actingAs($this->customer());

        $this->postJson("/api/v1/restaurants/{$merchant->id}/favourite")->assertNotFound();
    }
}
