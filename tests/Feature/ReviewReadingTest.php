<?php

namespace Tests\Feature;

use App\Enums\FulfilmentType;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\Reviews\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reading reviews back, rating dishes, and taking a review down.
 *
 * Ratings were collected and a score was published, but nobody could read a
 * word anyone wrote — which made the comment box a request to write into a
 * drawer. These are the three halves that were missing.
 */
class ReviewReadingTest extends TestCase
{
    use RefreshDatabase;

    private function customer(?string $name = null): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => $name ?? 'Customer '.$n,
            'phone' => '90500000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "rev.customer{$n}@example.in",
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
            'phone' => '91500000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "rev.owner{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $owner->id,
            'business_name' => 'Hotel '.$n,
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

    private function order(User $customer, Merchant $merchant, ?MenuItem $dish = null): Order
    {
        static $n = 0;
        $n++;

        $order = $merchant->orders()->create([
            'order_number' => 'NXV'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'user_id' => $customer->id,
            'status' => OrderStatus::Delivered,
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

        if ($dish !== null) {
            $order->items()->create([
                'menu_item_id' => $dish->id,
                'name' => $dish->name,
                'unit_price' => $dish->price,
                'quantity' => 1,
                'line_total' => $dish->price,
            ]);
        }

        return $order->fresh();
    }

    /** Leave a review as a fresh customer, and return it. */
    private function review(Merchant $merchant, int $rating, array $extra = [], ?MenuItem $dish = null): Order
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);

        $order = $this->order($customer, $merchant, $dish);

        $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => $rating, ...$extra])
            ->assertCreated();

        return $order->fresh();
    }

    public function test_a_restaurants_reviews_can_be_read(): void
    {
        $merchant = $this->restaurant();

        foreach ([5, 4, 2] as $score) {
            $this->review($merchant, $score, ['comment' => "Said {$score}"]);
        }

        Sanctum::actingAs($this->customer());

        $response = $this->getJson("/api/v1/restaurants/{$merchant->id}/reviews")->assertOk();

        $response->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.rating_count', 3)
            /*
             * The shape behind the score. A 4.2 built from forty fives and ten
             * ones is a different restaurant from a 4.2 where everyone said
             * four, and only the histogram tells them apart.
             */
            ->assertJsonPath('meta.breakdown.5', 1)
            ->assertJsonPath('meta.breakdown.2', 1)
            ->assertJsonPath('meta.breakdown.3', 0);

        // Newest first — the most recent night is the most useful one.
        $this->assertSame('Said 2', $response->json('data.0.comment'));
    }

    public function test_a_review_never_names_the_customer_in_full(): void
    {
        // A public list is the easiest place to tell a neighbourhood who
        // ordered what and when.
        $customer = $this->customer('Priya Raman');
        $merchant = $this->restaurant();

        Sanctum::actingAs($customer);
        $order = $this->order($customer, $merchant);
        $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => 5])->assertCreated();

        $json = $this->getJson("/api/v1/restaurants/{$merchant->id}/reviews")->assertOk()->json('data.0');
        $encoded = json_encode($json);

        $this->assertSame('Priya', $json['author']);
        $this->assertStringNotContainsString('Raman', $encoded);
        $this->assertStringNotContainsString($customer->phone, $encoded);
        $this->assertStringNotContainsString($customer->email, $encoded);
    }

    public function test_only_verified_restaurants_expose_reviews(): void
    {
        $merchant = $this->restaurant();
        $merchant->forceFill(['kyc_status' => KycStatus::Pending])->save();

        Sanctum::actingAs($this->customer());

        $this->getJson("/api/v1/restaurants/{$merchant->id}/reviews")->assertNotFound();
    }

    public function test_dishes_can_be_rated_and_the_menu_shows_it(): void
    {
        $merchant = $this->restaurant();
        $dish = $merchant->menuItems()->create(['name' => 'Masala Dosa', 'price' => 60, 'is_available' => true]);

        foreach ([5, 4, 3] as $score) {
            $this->review($merchant, $score, ['dishes' => [$dish->id => $score]], $dish);
        }

        // (5 + 4 + 3) / 3
        $this->assertSame(4.0, $dish->fresh()->rating);
        $this->assertSame(3, $dish->fresh()->rating_count);
    }

    public function test_a_dish_rating_is_withheld_until_enough_people_leave_one(): void
    {
        // "5.0" off a single review is noise wearing the clothes of a signal,
        // and a dish score is the one a customer trusts most.
        $merchant = $this->restaurant();
        $dish = $merchant->menuItems()->create(['name' => 'Idli', 'price' => 30, 'is_available' => true]);

        $this->review($merchant, 5, ['dishes' => [$dish->id => 5]], $dish);

        $this->assertNull($dish->fresh()->rating);
        $this->assertSame(1, $dish->fresh()->rating_count);
    }

    public function test_a_dish_that_was_not_ordered_cannot_be_rated(): void
    {
        /*
         * Otherwise one ₹30 idli buys the right to one-star every dish a
         * competitor sells, which is the cheapest sabotage available.
         */
        $merchant = $this->restaurant();
        $ordered = $merchant->menuItems()->create(['name' => 'Idli', 'price' => 30, 'is_available' => true]);
        $notOrdered = $merchant->menuItems()->create(['name' => 'Biryani', 'price' => 220, 'is_available' => true]);

        $order = $this->review(
            $merchant,
            5,
            ['dishes' => [$ordered->id => 5, $notOrdered->id => 1]],
            $ordered,
        );

        $this->assertSame(1, $order->review->items()->count());
        $this->assertSame(0, $notOrdered->fresh()->rating_count);
    }

    public function test_hiding_a_review_removes_it_and_moves_every_score_it_held_up(): void
    {
        $merchant = $this->restaurant();
        $dish = $merchant->menuItems()->create(['name' => 'Dosa', 'price' => 60, 'is_available' => true]);

        foreach ([5, 5, 5] as $score) {
            $this->review($merchant, $score, ['dishes' => [$dish->id => $score]], $dish);
        }

        $bad = $this->review($merchant, 1, ['comment' => 'abuse', 'dishes' => [$dish->id => 1]], $dish)->review;

        $this->assertSame(4.0, $merchant->fresh()->rating);

        app(ReviewService::class)->hide($bad, $this->customer(), 'Abusive language');

        // Restaurant and dish both move: a hidden review must not keep holding
        // an average down from behind a curtain.
        $this->assertSame(5.0, $merchant->fresh()->rating);
        $this->assertSame(5.0, $dish->fresh()->rating);
        $this->assertSame(3, $merchant->fresh()->rating_count);

        Sanctum::actingAs($this->customer());
        $this->getJson("/api/v1/restaurants/{$merchant->id}/reviews")
            ->assertOk()
            ->assertJsonCount(3, 'data');

        // The row survives — it is evidence if the takedown is disputed.
        $this->assertNotNull($bad->fresh());
        $this->assertSame('Abusive language', $bad->fresh()->hidden_reason);
    }

    public function test_a_restored_review_counts_again(): void
    {
        $merchant = $this->restaurant();

        foreach ([5, 5, 5] as $score) {
            $this->review($merchant, $score);
        }

        $bad = $this->review($merchant, 1)->review;

        $service = app(ReviewService::class);
        $service->hide($bad, $this->customer(), 'Mistaken takedown');
        $this->assertSame(5.0, $merchant->fresh()->rating);

        $service->unhide($bad);
        $this->assertSame(4.0, $merchant->fresh()->rating);
    }

    public function test_a_merchant_can_read_their_own_reviews(): void
    {
        // A score a merchant cannot explain is a score they cannot act on.
        $merchant = $this->restaurant();
        $this->review($merchant, 2, ['comment' => 'Cold when it arrived']);

        $this->actingAs($merchant->user)
            ->get('/merchants/reviews')
            ->assertOk()
            ->assertSee('Cold when it arrived');
    }

    public function test_a_merchant_cannot_read_another_restaurants_reviews(): void
    {
        $theirs = $this->restaurant();
        $this->review($theirs, 1, ['comment' => 'Private complaint']);

        $this->actingAs($this->restaurant()->user)
            ->get('/merchants/reviews')
            ->assertOk()
            ->assertDontSee('Private complaint');
    }

    public function test_the_moderation_screen_needs_a_reason(): void
    {
        // A takedown nobody has to justify is one nobody can review later.
        $merchant = $this->restaurant();
        $review = $this->review($merchant, 1, ['comment' => 'rude'])->review;

        $admin = User::create([
            'name' => 'Admin',
            'phone' => '9099999999',
            'email' => 'rev.admin@nexmile.in',
            'password' => 'secret',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($admin)
            ->post("/admin/reviews/{$review->id}/hide", [])
            ->assertSessionHasErrors('reason');

        $this->assertNull($review->fresh()->hidden_at);

        $this->actingAs($admin)
            ->post("/admin/reviews/{$review->id}/hide", ['reason' => 'Abusive language'])
            ->assertRedirect();

        $this->assertNotNull($review->fresh()->hidden_at);
        $this->assertSame($admin->id, $review->fresh()->hidden_by_user_id);
    }
}
