<?php

namespace Tests\Feature;

use App\Enums\FulfilmentType;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Rider;
use App\Models\RiderReport;
use App\Models\RiderWarning;
use App\Models\User;
use App\Services\Riders\ConductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reporting a rider, and what an admin does about it.
 *
 * The thing being protected here is the gap between the two. A report is what
 * a customer said; a warning is what Nexmile decided after reading it. If those
 * ever collapse into one step, a rider's income sits in the hands of whoever
 * complains loudest — and the most motivated complainant is someone who wants
 * their money back.
 */
class RiderConductTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role, string $prefix): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => ucfirst($prefix).' '.$n,
            'phone' => '93000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "conduct.{$prefix}{$n}@example.in",
            'password' => 'secret',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function rider(): Rider
    {
        return $this->user(UserRole::Rider, 'rider')->rider()->create([
            'full_name' => 'Selvam K',
            'vehicle_type' => 'motorcycle',
            'kyc_status' => KycStatus::Verified,
            'driving_licence_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
        ]);
    }

    private function restaurant(): Merchant
    {
        return Merchant::create([
            'user_id' => $this->user(UserRole::Merchant, 'shop')->id,
            'business_name' => 'Ponnusamy Hotel',
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

    private function order(User $customer, Rider $rider, OrderStatus $status = OrderStatus::Delivered): Order
    {
        static $n = 0;
        $n++;

        return $this->restaurant()->orders()->create([
            'order_number' => 'NXC'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'user_id' => $customer->id,
            'rider_id' => $rider->id,
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
        ])->fresh();
    }

    private function admin(): User
    {
        return $this->user(UserRole::Admin, 'admin');
    }

    // ---------------------------------------------------------------- the app

    public function test_the_reasons_a_customer_can_pick_come_from_the_server(): void
    {
        Sanctum::actingAs($this->user(UserRole::Customer, 'cust'));

        $response = $this->getJson('/api/v1/report-categories')->assertOk();

        // So the list can change without an app release, and so the app can
        // never send a category the backend does not recognise.
        $values = collect($response->json('data'))->pluck('value');

        $this->assertContains('extra_money', $values);
        $this->assertContains('other', $values);
        $this->assertSame(
            array_keys(config('conduct.report_categories')),
            $values->all(),
        );
    }

    public function test_a_customer_can_report_the_rider_who_brought_their_order(): void
    {
        $customer = $this->user(UserRole::Customer, 'cust');
        $rider = $this->rider();
        $order = $this->order($customer, $rider);

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/report-rider", [
            'category' => 'extra_money',
            'description' => 'Asked for ₹50 on top of the bill.',
        ])->assertCreated();

        $report = RiderReport::sole();

        $this->assertSame($rider->id, $report->rider_id);
        $this->assertSame(RiderReport::PENDING, $report->status);

        /*
         * Nothing happened to the rider. This is the whole point of the
         * design: the report is a message to a person, not a penalty.
         */
        $this->assertSame(0, RiderWarning::count());
        $this->assertTrue($rider->fresh()->user->status === UserStatus::Active);
    }

    public function test_a_customer_cannot_report_someone_elses_delivery(): void
    {
        $order = $this->order($this->user(UserRole::Customer, 'cust'), $this->rider());

        // A stranger reporting a rider they never met is the cheapest possible
        // way to build a fake pattern against them.
        Sanctum::actingAs($this->user(UserRole::Customer, 'cust'));

        $this->postJson("/api/v1/orders/{$order->id}/report-rider", ['category' => 'rude'])
            ->assertNotFound();

        $this->assertSame(0, RiderReport::count());
    }

    public function test_a_delivery_can_only_be_reported_once_it_is_finished(): void
    {
        $customer = $this->user(UserRole::Customer, 'cust');
        $order = $this->order($customer, $this->rider(), OrderStatus::PickedUp);

        Sanctum::actingAs($customer);

        /*
         * A complaint that lands while the rider is still carrying the food
         * invites the obvious next step — suspending them — which strands the
         * order the customer is waiting for.
         */
        $this->postJson("/api/v1/orders/{$order->id}/report-rider", ['category' => 'rude'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');
    }

    public function test_the_same_delivery_cannot_be_reported_twice(): void
    {
        $customer = $this->user(UserRole::Customer, 'cust');
        $order = $this->order($customer, $this->rider());

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/report-rider", ['category' => 'rude'])
            ->assertCreated();

        // Saying it twice does not make it twice as true, and three warnings
        // is a threshold one angry evening should not be able to reach alone.
        $this->postJson("/api/v1/orders/{$order->id}/report-rider", ['category' => 'unsafe'])
            ->assertStatus(422);

        $this->assertSame(1, RiderReport::count());
    }

    public function test_a_category_the_backend_does_not_know_is_refused(): void
    {
        $customer = $this->user(UserRole::Customer, 'cust');
        $order = $this->order($customer, $this->rider());

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/report-rider", ['category' => 'stole_my_dog'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    // -------------------------------------------------------------- the admin

    public function test_an_admin_reads_the_queue_and_upholds_a_report(): void
    {
        $customer = $this->user(UserRole::Customer, 'cust');
        $rider = $this->rider();
        $order = $this->order($customer, $rider);

        $report = app(ConductService::class)
            ->report($order, $customer, 'extra_money', 'Asked for ₹50 extra.');

        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/conduct')
            ->assertOk()
            ->assertSee('Asked for extra money')
            ->assertSee('Asked for ₹50 extra.', false);

        $this->actingAs($admin)
            ->post("/admin/conduct/reports/{$report->id}/uphold", [
                'note' => 'Confirmed with the merchant. Told him not to do it again.',
            ])
            ->assertRedirect();

        $this->assertSame(RiderReport::UPHELD, $report->fresh()->status);

        // Upholding and warning are one act: a warning nobody had to justify
        // against a report is a warning with no reasoning behind it.
        $warning = RiderWarning::sole();
        $this->assertSame($rider->id, $warning->rider_id);
        $this->assertSame($report->id, $warning->rider_report_id);
        $this->assertSame($admin->id, $warning->issued_by_user_id);
    }

    public function test_a_dismissed_report_is_recorded_but_warns_nobody(): void
    {
        $customer = $this->user(UserRole::Customer, 'cust');
        $order = $this->order($customer, $this->rider());

        $report = app(ConductService::class)->report($order, $customer, 'never_arrived', null);

        $this->actingAs($this->admin())
            ->post("/admin/conduct/reports/{$report->id}/dismiss", [
                'note' => 'GPS trail and the photo show it was handed over.',
            ])
            ->assertRedirect();

        $this->assertSame(RiderReport::DISMISSED, $report->fresh()->status);
        $this->assertSame(0, RiderWarning::count());

        /*
         * Kept, not deleted. The next admin reading this rider's file needs to
         * see that a complaint was made and answered — otherwise the same
         * question gets re-litigated every time.
         */
        $this->assertNotNull($report->fresh()->review_note);
    }

    public function test_a_decision_cannot_be_recorded_without_saying_why(): void
    {
        $customer = $this->user(UserRole::Customer, 'cust');
        $order = $this->order($customer, $this->rider());

        $report = app(ConductService::class)->report($order, $customer, 'rude', null);

        // The note is what the rider is owed if they ask what happened.
        $this->actingAs($this->admin())
            ->post("/admin/conduct/reports/{$report->id}/uphold", ['note' => ''])
            ->assertSessionHasErrors('note');

        $this->assertSame(RiderReport::PENDING, $report->fresh()->status);
        $this->assertSame(0, RiderWarning::count());
    }

    public function test_an_admin_can_warn_for_something_raised_outside_the_app(): void
    {
        $rider = $this->rider();

        // A merchant complaint is still a warning. Forcing a fake customer
        // report to record it would corrupt the reports table for good.
        $this->actingAs($this->admin())
            ->post("/admin/conduct/riders/{$rider->id}/warn", [
                'reason' => 'Hotel Vasanth called — argued with the kitchen staff.',
            ])
            ->assertRedirect();

        $warning = RiderWarning::sole();
        $this->assertNull($warning->rider_report_id);
    }

    // ------------------------------------------------------------- the counter

    public function test_three_warnings_flag_a_rider_for_review_and_nothing_more(): void
    {
        $rider = $this->rider();
        $admin = $this->admin();
        $conduct = app(ConductService::class);

        foreach (range(1, 3) as $i) {
            $conduct->warn($rider, $admin, "Late without telling anyone ({$i}).");
        }

        $standing = $conduct->standing($rider->fresh());

        $this->assertTrue($standing['flagged']);
        $this->assertSame(3, $standing['warnings']);

        /*
         * Flagged, not terminated. Nobody loses their income to a counter —
         * the count exists so a pattern cannot be missed, not so the decision
         * can be handed to arithmetic.
         */
        $this->assertSame(UserStatus::Active, $rider->fresh()->user->status);

        $this->actingAs($admin)->get('/admin/conduct')
            ->assertOk()
            ->assertSee('Needs review');
    }

    public function test_two_warnings_are_not_a_pattern(): void
    {
        $rider = $this->rider();
        $conduct = app(ConductService::class);

        foreach (range(1, 2) as $i) {
            $conduct->warn($rider, $this->admin(), "Late ({$i}).");
        }

        $this->assertFalse($conduct->standing($rider->fresh())['flagged']);
    }

    public function test_a_warning_stops_counting_once_it_is_old(): void
    {
        $rider = $this->rider();
        $admin = $this->admin();
        $conduct = app(ConductService::class);

        foreach (range(1, 3) as $i) {
            $conduct->warn($rider, $admin, "Old trouble ({$i}).");
        }

        // Warnings that never expire mean one bad month follows a rider
        // forever, and someone with nothing to gain from improving leaves.
        RiderWarning::query()->update([
            'created_at' => now()->subDays((int) config('conduct.warning_window_days') + 1),
        ]);

        $standing = $conduct->standing($rider->fresh());

        $this->assertFalse($standing['flagged']);
        $this->assertSame(0, $standing['warnings']);
    }

    public function test_a_quietly_bad_rating_flags_a_rider_nobody_reported(): void
    {
        $rider = $this->rider();

        /*
         * A rider nobody bothers to report but everybody scores 2 never
         * appears in a queue built out of complaints.
         */
        $rider->forceFill(['rating' => 2.4, 'rating_count' => 12])->save();

        $standing = app(ConductService::class)->standing($rider->fresh());

        $this->assertTrue($standing['flagged']);
        $this->assertStringContainsString('rating 2.4', implode(' ', $standing['reasons']));
    }

    public function test_a_low_rating_from_a_handful_of_orders_is_not_yet_evidence(): void
    {
        $rider = $this->rider();

        // Three bad nights out of three is noise, not a pattern.
        $rider->forceFill(['rating' => 2.0, 'rating_count' => 3])->save();

        $this->assertFalse(app(ConductService::class)->standing($rider->fresh())['flagged']);
    }

    public function test_a_riders_file_shows_reports_warnings_and_standing_together(): void
    {
        $customer = $this->user(UserRole::Customer, 'cust');
        $rider = $this->rider();
        $order = $this->order($customer, $rider);
        $admin = $this->admin();
        $conduct = app(ConductService::class);

        $report = $conduct->report($order, $customer, 'food_damaged', 'Sambar all over the bag.');
        $conduct->uphold($report, $admin, 'Bag was not upright. Reissued a carrier.');

        $this->actingAs($admin)->get("/admin/conduct/riders/{$rider->id}")
            ->assertOk()
            ->assertSee('Selvam K')
            ->assertSee('Sambar all over the bag.', false)
            ->assertSee('Bag was not upright. Reissued a carrier.', false);
    }

    public function test_the_conduct_queue_does_not_cost_a_query_per_rider(): void
    {
        // The queue asks about every rider on the roster to build the "needs
        // review" list. A query each grows with hiring, until the page an
        // admin opens most often is the slowest one in the portal.
        foreach (range(1, 20) as $ignored) {
            $this->rider();
        }

        $admin = $this->admin();

        \DB::flushQueryLog();
        \DB::enableQueryLog();

        $this->actingAs($admin)->get('/admin/conduct')->assertOk();

        $count = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(
            12,
            $count,
            "The conduct queue ran {$count} queries for 20 riders — something is running per rider.",
        );
    }

    public function test_the_conduct_queue_is_not_public(): void
    {
        $this->get('/admin/conduct')->assertRedirect();

        // A customer with a login is still not an admin. Names, addresses and
        // complaints about named riders all sit behind this one route.
        $this->actingAs($this->user(UserRole::Customer, 'cust'))
            ->get('/admin/conduct')
            ->assertForbidden();
    }

    // -------------------------------------------------------------- the rating

    public function test_a_rider_rating_left_with_a_review_is_actually_stored(): void
    {
        $rider = $this->rider();

        /*
         * rider_rating was collected on every review and written nowhere, so
         * the score the conduct flag reads was always null. Worth a test of
         * its own — a number nobody stores looks identical to a good one.
         */
        foreach ([2, 2, 2] as $score) {
            $customer = $this->user(UserRole::Customer, 'cust');
            $order = $this->order($customer, $rider);

            Sanctum::actingAs($customer);

            $this->postJson("/api/v1/orders/{$order->id}/review", [
                'rating' => 5,
                'rider_rating' => $score,
            ])->assertCreated();
        }

        $rider->refresh();

        $this->assertEqualsWithDelta(2.0, (float) $rider->rating, 0.01);
        $this->assertSame(3, $rider->rating_count);
    }

    public function test_one_rating_is_counted_but_not_yet_published(): void
    {
        $customer = $this->user(UserRole::Customer, 'cust');
        $rider = $this->rider();
        $order = $this->order($customer, $rider);

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 5,
            'rider_rating' => 1,
        ])->assertCreated();

        $rider->refresh();

        /*
         * One bad night is not a score. It is counted, so the average is right
         * once there are enough of them, but nothing is published off it — and
         * the conduct flag needs ten before it looks at the rating at all.
         */
        $this->assertSame(1, $rider->rating_count);
        $this->assertNull($rider->rating);
    }
}
