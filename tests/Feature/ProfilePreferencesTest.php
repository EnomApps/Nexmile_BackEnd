<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\SendPushNotification;
use App\Models\AppFeedback;
use App\Models\User;
use App\Services\Push\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Veg mode, offer notifications and app feedback — the three parts of the new
 * customer profile page that needed backend work and had no open questions.
 */
class ProfilePreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Cust '.$n,
            'phone' => '97610000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "pref{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
            ...$attributes,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Preferences
    |--------------------------------------------------------------------------
    */

    public function test_a_new_customer_starts_with_veg_mode_off_and_offers_on(): void
    {
        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/profile/preferences')
            ->assertOk()
            ->assertExactJson(['data' => [
                'veg_mode' => false,
                // Opt-out, not opt-in: defaulting this off would silence every
                // offer for everybody who never finds the setting.
                'promotional_notifications' => true,
            ]]);
    }

    public function test_one_field_can_be_changed_and_both_come_back(): void
    {
        Sanctum::actingAs($user = $this->customer());

        $this->patchJson('/api/v1/profile/preferences', ['veg_mode' => true])
            ->assertOk()
            ->assertJsonPath('data.veg_mode', true)
            // Untouched, and still sent, so the app never has to merge a
            // partial answer into what it already had.
            ->assertJsonPath('data.promotional_notifications', true);

        $this->assertTrue($user->fresh()->veg_mode);
    }

    public function test_offers_can_be_turned_off(): void
    {
        Sanctum::actingAs($user = $this->customer());

        $this->patchJson('/api/v1/profile/preferences', ['promotional_notifications' => false])
            ->assertOk()
            ->assertJsonPath('data.promotional_notifications', false);

        $this->assertFalse($user->fresh()->promotional_notifications);
    }

    public function test_the_answer_is_json_booleans_not_ones_and_zeroes(): void
    {
        Sanctum::actingAs($this->customer(['veg_mode' => true]));

        $data = $this->getJson('/api/v1/profile/preferences')->assertOk()->json('data');

        // A driver handing back 1 would still pass assertJsonPath loosely.
        $this->assertIsBool($data['veg_mode']);
        $this->assertIsBool($data['promotional_notifications']);
    }

    public function test_a_value_that_is_not_a_boolean_is_refused(): void
    {
        Sanctum::actingAs($user = $this->customer());

        $this->patchJson('/api/v1/profile/preferences', ['veg_mode' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('veg_mode');

        $this->assertFalse($user->fresh()->veg_mode);
    }

    public function test_an_empty_change_is_accepted_rather_than_refused(): void
    {
        Sanctum::actingAs($this->customer());

        // A 422 for "you changed nothing" is an error about a harmless tap.
        $this->patchJson('/api/v1/profile/preferences', [])
            ->assertOk()
            ->assertJsonPath('data.veg_mode', false);
    }

    public function test_preferences_follow_the_account_not_the_handset(): void
    {
        $user = $this->customer();

        Sanctum::actingAs($user);
        $this->patchJson('/api/v1/profile/preferences', ['veg_mode' => true])->assertOk();

        // A second sign-in is a new token, which is what a new phone is.
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/profile/preferences')
            ->assertOk()
            ->assertJsonPath('data.veg_mode', true);
    }

    public function test_preferences_are_not_readable_without_signing_in(): void
    {
        $this->getJson('/api/v1/profile/preferences')->assertStatus(401);
        $this->patchJson('/api/v1/profile/preferences', ['veg_mode' => true])->assertStatus(401);
    }

    public function test_one_customer_cannot_change_anothers_preferences(): void
    {
        $theirs = $this->customer();

        Sanctum::actingAs($this->customer());
        $this->patchJson('/api/v1/profile/preferences', ['veg_mode' => true])->assertOk();

        // The endpoint has no id in it — it only ever writes the caller's row.
        $this->assertFalse($theirs->fresh()->veg_mode);
    }

    /*
    |--------------------------------------------------------------------------
    | What the opt-out actually stops
    |--------------------------------------------------------------------------
    */

    public function test_a_marketing_push_skips_customers_who_opted_out(): void
    {
        Queue::fake();

        $wants = $this->customer();
        $muted = $this->customer(['promotional_notifications' => false]);

        app(PushService::class)->promotionalToUsers(
            [$wants->id, $muted->id],
            PushService::CUSTOMER,
            'push.test.offer',
        );

        Queue::assertPushed(
            SendPushNotification::class,
            fn (SendPushNotification $job) => in_array($wants->id, $job->userIds, true)
                && ! in_array($muted->id, $job->userIds, true),
        );
    }

    public function test_a_marketing_push_to_nobody_who_wants_it_queues_nothing(): void
    {
        Queue::fake();

        $muted = $this->customer(['promotional_notifications' => false]);

        app(PushService::class)->promotionalToUsers(
            [$muted->id],
            PushService::CUSTOMER,
            'push.test.offer',
        );

        Queue::assertNothingPushed();
    }

    public function test_an_order_update_ignores_the_offer_setting(): void
    {
        Queue::fake();

        $muted = $this->customer(['promotional_notifications' => false]);

        /*
         * "Your rider is outside" is not marketing. A customer who muted
         * offers has not asked to stop being told where their food is, so the
         * ordinary path must not consult the flag.
         */
        app(PushService::class)->toUsers(
            [$muted->id],
            PushService::CUSTOMER,
            'push.test.order',
        );

        Queue::assertPushed(SendPushNotification::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Feedback
    |--------------------------------------------------------------------------
    */

    public function test_a_customer_can_rate_the_app(): void
    {
        Sanctum::actingAs($user = $this->customer());

        $this->postJson('/api/v1/feedback', [
            'rating' => 4,
            'message' => 'Quick delivery, hot food.',
            'platform' => 'android',
            'app_version' => '1.4.0 (12)',
        ])->assertCreated();

        $row = AppFeedback::sole();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame(4, $row->rating);
        $this->assertSame('1.4.0 (12)', $row->app_version);
    }

    public function test_a_rating_on_its_own_is_enough(): void
    {
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/feedback', ['rating' => 5])->assertCreated();

        $this->assertNull(AppFeedback::sole()->message);
    }

    public function test_a_rating_outside_one_to_five_is_refused(): void
    {
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/feedback', ['rating' => 6])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rating');

        $this->postJson('/api/v1/feedback', ['rating' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rating');

        $this->postJson('/api/v1/feedback', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rating');

        $this->assertSame(0, AppFeedback::count());
    }

    public function test_an_overlong_message_is_refused_under_its_own_key(): void
    {
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/feedback', [
            'rating' => 3,
            'message' => str_repeat('a', 1001),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_an_unknown_platform_is_refused_but_a_missing_one_is_not(): void
    {
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/v1/feedback', ['rating' => 3, 'platform' => 'symbian'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');

        /*
         * Nullable on purpose. Losing a customer's sentence because their
         * build forgot to name itself is a worse trade than a row with an
         * unknown platform.
         */
        $this->postJson('/api/v1/feedback', ['rating' => 3])->assertCreated();
    }

    public function test_feedback_needs_a_signed_in_customer(): void
    {
        $this->postJson('/api/v1/feedback', ['rating' => 5])->assertStatus(401);
    }

    public function test_closing_an_account_keeps_the_sentence_and_drops_the_name(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $this->postJson('/api/v1/feedback', ['rating' => 2, 'message' => 'Slow on 2G.'])->assertCreated();

        $user->forceDelete();

        /*
         * nullOnDelete. The customer took their name with them; the sentence
         * that told us the app was slow on 2G stayed, and with nobody attached
         * it is no longer personal data.
         */
        $row = AppFeedback::sole();
        $this->assertNull($row->user_id);
        $this->assertSame('Slow on 2G.', $row->message);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading it in the admin panel
    |--------------------------------------------------------------------------
    */

    private function admin(): User
    {
        return $this->customer(['role' => UserRole::Admin]);
    }

    public function test_an_admin_can_read_what_customers_said(): void
    {
        $customer = $this->customer(['name' => 'Meena R']);

        AppFeedback::create([
            'user_id' => $customer->id,
            'rating' => 2,
            'message' => 'Map does not load on 2G.',
            'platform' => 'android',
            'app_version' => '1.4.0 (12)',
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/feedback')
            ->assertOk()
            ->assertSee('Map does not load on 2G.')
            ->assertSee('Meena R')
            ->assertSee('1.4.0 (12)');
    }

    public function test_the_admin_page_counts_low_ratings_separately(): void
    {
        foreach ([1, 1, 5, 5] as $rating) {
            AppFeedback::create(['user_id' => $this->customer()->id, 'rating' => $rating]);
        }

        /*
         * The reason the count exists: two ones and two fives average three,
         * and so do four threes. One of those is a problem.
         */
        $this->actingAs($this->admin())
            ->get('/admin/feedback')
            ->assertOk()
            ->assertViewHas('summary', fn (array $summary) => $summary['average'] === 3.0
                && $summary['low'] === 2
                && $summary['total'] === 4);
    }

    public function test_the_admin_page_can_isolate_one_build(): void
    {
        AppFeedback::create([
            'user_id' => $this->customer()->id, 'rating' => 1,
            'message' => 'Crashes on open.', 'app_version' => '1.4.1 (13)',
        ]);
        AppFeedback::create([
            'user_id' => $this->customer()->id, 'rating' => 5,
            'message' => 'Works beautifully.', 'app_version' => '1.4.0 (12)',
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/feedback?version='.urlencode('1.4.1 (13)'))
            ->assertOk()
            ->assertSee('Crashes on open.')
            ->assertDontSee('Works beautifully.');
    }

    public function test_a_closed_account_is_shown_without_a_name(): void
    {
        $customer = $this->customer(['name' => 'Gone Away']);
        AppFeedback::create(['user_id' => $customer->id, 'rating' => 3, 'message' => 'It was fine.']);
        $customer->forceDelete();

        $this->actingAs($this->admin())
            ->get('/admin/feedback')
            ->assertOk()
            ->assertSee('It was fine.')
            ->assertSee('account closed')
            ->assertDontSee('Gone Away');
    }

    public function test_a_customer_cannot_read_the_feedback_page(): void
    {
        AppFeedback::create(['user_id' => $this->customer()->id, 'rating' => 1, 'message' => 'Secret gripe.']);

        $this->actingAs($this->customer())
            ->get('/admin/feedback')
            ->assertStatus(403);
    }
}
