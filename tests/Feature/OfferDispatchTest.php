<?php

namespace Tests\Feature;

use App\Contracts\PushSender;
use App\Enums\DispatchStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\RiderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\CloseDispatchWave;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Rider;
use App\Models\RiderOffer;
use App\Models\User;
use App\Services\Orders\OfferDispatchService;
use App\Services\Push\PushService;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/**
 * Offer dispatch: the ringing order card (EP7).
 *
 * Extends DispatchTest for the order and rider helpers — a real order walked
 * through a real checkout is the only honest input to dispatch.
 *
 * CloseDispatchWave is faked throughout. On the sync queue a delayed job runs
 * immediately, so without the fake every wave would close inside the request
 * that started it and an order would race through all three waves to
 * no_rider_found before the test got a chance to accept anything. The job is
 * then run by hand where its behaviour is the thing under test.
 */
class OfferDispatchTest extends DispatchTest
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dispatch.mode' => 'push',
            'dispatch.start_at' => 'accepted',
        ]);

        Queue::fake([CloseDispatchWave::class]);
    }

    // ---------------------------------------------------------------- setup

    /** An order the kitchen has accepted but not finished cooking. */
    protected function acceptedOrder(?Merchant $shop = null, ?User $customer = null): Order
    {
        $customer ??= $this->customer();
        $shop ??= $this->restaurant();

        Sanctum::actingAs($customer);
        $address = $this->addressFor($customer);
        $dish = $this->dish($shop);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id, 'quantity' => 2,
        ])->assertCreated();

        $order = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery', 'payment_method' => 'cod', 'address_id' => $address->id,
        ])->assertCreated()->json('data');

        Sanctum::actingAs($shop->user);
        $this->postJson("/api/v1/merchant/orders/{$order['id']}/accept")->assertOk();

        return Order::with(['merchant', 'items'])->find($order['id']);
    }

    /** An ops account, for the stranded-order endpoints. */
    protected function admin(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Dispatch admin '.$n,
            'phone' => '97960000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "dispatchadmin{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    /** Gives a rider a phone, so a push to them has somewhere to land. */
    protected function withDevice(Rider $rider): Rider
    {
        app(PushService::class)->register(
            $rider->user,
            'rider-token-'.$rider->id,
            'android',
            PushService::RIDER,
        );

        return $rider;
    }

    /** A sender that keeps every data payload it was handed. */
    protected function recordingSender(): object
    {
        $sender = new class implements PushSender
        {
            /** @var list<array{title: string, body: string}> */
            public array $notifications = [];

            /** @var list<array<string, string>> */
            public array $payloads = [];

            /** @var list<int|null> */
            public array $ttls = [];

            public function send(
                array $tokens,
                string $title,
                string $body,
                array $data = [],
                ?string $collapseKey = null,
            ): array {
                $this->notifications[] = ['title' => $title, 'body' => $body, ...$data];

                return [];
            }

            public function sendData(
                array $tokens,
                array $data,
                ?string $collapseKey = null,
                ?int $ttlSeconds = null,
            ): array {
                $this->payloads[] = $data;
                $this->ttls[] = $ttlSeconds;

                return [];
            }
        };

        $this->app->instance(PushSender::class, $sender);

        return $sender;
    }

    /** @return array<string, string>|null */
    protected function payloadOfType(object $sender, string $type): ?array
    {
        foreach ($sender->payloads as $payload) {
            if (($payload['type'] ?? null) === $type) {
                return $payload;
            }
        }

        return null;
    }

    // ------------------------------------------------- the offer goes out

    public function test_the_kitchen_accepting_an_order_rings_nearby_riders(): void
    {
        /*
         * The whole reason dispatch starts at 'accepted' rather than at
         * 'ready': the rider travels while the food cooks, and arrives as it
         * comes out of the kitchen instead of a quarter of an hour later.
         */
        $riderA = $this->withDevice($this->rider());
        $riderB = $this->withDevice($this->rider());

        $sender = $this->recordingSender();

        $order = $this->acceptedOrder();

        $this->assertSame(DispatchStatus::Searching->value, $order->fresh()->dispatch_status);
        $this->assertSame(1, $order->fresh()->dispatch_wave);

        $this->assertEqualsCanonicalizing(
            [$riderA->id, $riderB->id],
            RiderOffer::where('order_id', $order->id)->pluck('rider_id')->all(),
        );

        // And the food is still being cooked, which is the point.
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);

        $card = $this->payloadOfType($sender, 'ORDER_OFFER');

        $this->assertNotNull($card, 'no ORDER_OFFER data message was sent');
        $this->assertSame((string) $order->id, $card['order_id']);
        $this->assertNotEmpty($card['offer_id']);
        $this->assertNotEmpty($card['expires_at']);
    }

    public function test_the_offer_card_does_not_carry_the_customers_address_or_phone(): void
    {
        /*
         * An offer goes to up to fifteen phones, fourteen of which will never
         * touch this order. A rider who is only being asked has not been
         * trusted with where somebody lives.
         */
        $this->withDevice($this->rider());

        $sender = $this->recordingSender();

        $order = $this->acceptedOrder();

        $card = $this->payloadOfType($sender, 'ORDER_OFFER');
        $flat = strtolower(implode('|', $card));

        $this->assertStringNotContainsString(strtolower($order->delivery_line1), $flat);
        $this->assertStringNotContainsString((string) $order->delivery_contact_phone, $flat);
        $this->assertStringNotContainsString(strtolower((string) $order->delivery_pincode), $flat);

        // The area is there, because a rider needs to judge the job.
        $this->assertSame($order->delivery_city, $card['dropoff_area']);
    }

    public function test_the_card_expires_on_the_servers_clock_not_the_apps(): void
    {
        // A phone with a wrong clock, or a message that sat in a queue, must
        // not be able to leave a card ringing past its window.
        $this->withDevice($this->rider());

        $sender = $this->recordingSender();

        $order = $this->acceptedOrder();

        $window = (int) config('dispatch.waves.0.seconds');
        $offer = RiderOffer::where('order_id', $order->id)->firstOrFail();

        $this->assertEqualsWithDelta($window, $offer->offered_at->diffInSeconds($offer->expires_at), 2);

        // And FCM is told to drop it rather than hold it for a phone that is
        // switched off: a card delivered late is not late, it is wrong.
        $this->assertNotNull($sender->ttls[0]);
        $this->assertLessThanOrEqual($window, $sender->ttls[0]);
    }

    public function test_a_wave_schedules_its_own_ending(): void
    {
        // Every timeout in dispatch is a delayed job. Nothing sweeps, nothing
        // polls, and nothing else will close this wave.
        $this->rider();

        $order = $this->acceptedOrder();

        Queue::assertPushed(
            CloseDispatchWave::class,
            fn (CloseDispatchWave $job) => $job->orderId === $order->id && $job->wave === 1,
        );
    }

    public function test_a_rider_is_never_offered_their_own_order(): void
    {
        /*
         * Otherwise: order food, deliver it to yourself, mark it delivered,
         * keep the delivery fee. Repeatable at will, and it looks like a
         * rider who is simply very fast.
         */
        $rider = $this->rider();

        $order = $this->acceptedOrder(customer: $rider->user);

        $this->assertSame(0, RiderOffer::where('order_id', $order->id)->count());
    }

    public function test_a_rider_with_a_card_already_up_is_not_sent_another(): void
    {
        // One popup at a time, or a rider answers the wrong one.
        $rider = $this->rider();

        $first = $this->acceptedOrder();
        $this->assertSame(1, RiderOffer::where('order_id', $first->id)->count());

        $second = $this->acceptedOrder();
        $this->assertSame(0, RiderOffer::where('order_id', $second->id)->count());
    }

    public function test_an_off_duty_rider_is_not_rung(): void
    {
        $this->rider(['duty_status' => RiderStatus::Offline]);

        $order = $this->acceptedOrder();

        $this->assertSame(0, RiderOffer::where('order_id', $order->id)->count());
    }

    // ------------------------------------------------------ first accept wins

    public function test_the_first_rider_to_accept_gets_it_and_the_second_is_refused(): void
    {
        /*
         * Two riders tapping at the same millisecond is the normal case, not
         * the edge case. 409 rather than 422 because the popup has to tell
         * "somebody beat you" apart from "you cannot take this".
         */
        $winner = $this->rider();
        $loser = $this->rider();

        $order = $this->acceptedOrder();

        $winning = RiderOffer::where('order_id', $order->id)->where('rider_id', $winner->id)->firstOrFail();
        $losing = RiderOffer::where('order_id', $order->id)->where('rider_id', $loser->id)->firstOrFail();

        Sanctum::actingAs($winner->user);
        $this->postJson("/api/v1/rider/offers/{$winning->ulid}/accept")->assertOk();

        Sanctum::actingAs($loser->user);
        $this->postJson("/api/v1/rider/offers/{$losing->ulid}/accept")->assertStatus(409);

        $order->refresh();

        $this->assertSame($winner->id, $order->rider_id);
        $this->assertSame(DispatchStatus::Assigned->value, $order->dispatch_status);
        $this->assertNotNull($order->assigned_at);
    }

    public function test_accepting_withdraws_everybody_elses_card(): void
    {
        /*
         * Twenty-nine seconds of ringtone for an order that is already gone
         * is how a rider learns to ignore the sound. Sent as its own message
         * rather than left to expire.
         */
        $winner = $this->rider();
        $loser = $this->withDevice($this->rider());

        $order = $this->acceptedOrder();

        $sender = $this->recordingSender();

        $offer = RiderOffer::where('order_id', $order->id)->where('rider_id', $winner->id)->firstOrFail();

        Sanctum::actingAs($winner->user);
        $this->postJson("/api/v1/rider/offers/{$offer->ulid}/accept")->assertOk();

        $cancellation = $this->payloadOfType($sender, 'OFFER_CANCELLED');

        $this->assertNotNull($cancellation, 'the losing rider was never told');
        $this->assertSame('taken', $cancellation['reason']);
        $this->assertSame((string) $order->id, $cancellation['order_id']);

        $this->assertSame(
            OfferStatus::Cancelled,
            RiderOffer::where('order_id', $order->id)->where('rider_id', $loser->id)->firstOrFail()->status,
        );

        // The winner's own offer is accepted, not cancelled.
        $this->assertSame(OfferStatus::Accepted, $offer->fresh()->status);
    }

    public function test_an_expired_card_is_refused_with_410(): void
    {
        /*
         * 410 and not 409: the countdown already ran out on the rider's
         * screen, so the popup closes silently rather than announcing
         * something the rider watched happen.
         */
        $rider = $this->rider();

        $order = $this->acceptedOrder();

        $offer = RiderOffer::where('order_id', $order->id)->firstOrFail();
        $offer->forceFill(['expires_at' => now()->subSecond()])->save();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/offers/{$offer->ulid}/accept")->assertStatus(410);

        $this->assertNull($order->fresh()->rider_id);
    }

    public function test_accepting_twice_is_not_a_loss(): void
    {
        // A dropped response and a retry must not tell a rider they lost an
        // order they are already carrying.
        $rider = $this->rider();

        $order = $this->acceptedOrder();
        $offer = RiderOffer::where('order_id', $order->id)->firstOrFail();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/offers/{$offer->ulid}/accept")->assertOk();
        $this->postJson("/api/v1/rider/offers/{$offer->ulid}/accept")->assertOk();

        $this->assertSame($rider->id, $order->fresh()->rider_id);
    }

    public function test_one_riders_card_cannot_be_accepted_by_another(): void
    {
        $mine = $this->rider();
        $theirs = $this->rider();

        $order = $this->acceptedOrder();

        $offer = RiderOffer::where('order_id', $order->id)->where('rider_id', $theirs->id)->firstOrFail();

        Sanctum::actingAs($mine->user);
        $this->postJson("/api/v1/rider/offers/{$offer->ulid}/accept")->assertNotFound();

        $this->assertNull($order->fresh()->rider_id);
    }

    public function test_accepting_while_the_food_cooks_leaves_mark_ready_with_the_merchant(): void
    {
        /*
         * The reason dispatch state is a separate column from order status.
         * One chain — confirmed, searching, assigned — would take "mark
         * ready" away from a merchant who is still cooking, and break the
         * kitchen screen on exactly the orders that are going well.
         */
        $rider = $this->rider();

        $order = $this->acceptedOrder();
        $offer = RiderOffer::where('order_id', $order->id)->firstOrFail();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/offers/{$offer->ulid}/accept")->assertOk();

        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);

        Sanctum::actingAs($order->merchant->user);
        $this->postJson("/api/v1/merchant/orders/{$order->id}/ready")->assertOk();

        // Ready *and* already carried — so the status lands on rider_assigned
        // rather than sitting on a board nobody is watching.
        $this->assertSame(OrderStatus::RiderAssigned, $order->fresh()->status);
        $this->assertSame($rider->id, $order->fresh()->rider_id);
    }

    // ------------------------------------------------------------ declining

    public function test_declining_frees_the_rider_without_counting_against_them(): void
    {
        /*
         * Declining is answering. A system that punishes an honest "no"
         * teaches riders to let it ring instead, which costs everyone thirty
         * seconds rather than none.
         */
        $rider = $this->rider();

        $order = $this->acceptedOrder();
        $offer = RiderOffer::where('order_id', $order->id)->firstOrFail();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/offers/{$offer->ulid}/reject", ['reason' => 'Too far'])->assertOk();

        $this->assertSame(OfferStatus::Rejected, $offer->fresh()->status);
        $this->assertSame('Too far', $offer->fresh()->reason);
        $this->assertSame(0, $rider->fresh()->consecutive_misses);

        // Free to be rung about something else straight away.
        $this->assertFalse(app(OfferDispatchService::class)->hasLiveOffer($rider->fresh()));
    }

    public function test_declining_an_offer_that_has_already_ended_is_still_fine(): void
    {
        // The card is gone from the rider's screen either way, and an error
        // about it would be an error about nothing.
        $rider = $this->rider();

        $order = $this->acceptedOrder();
        $offer = RiderOffer::where('order_id', $order->id)->firstOrFail();
        $offer->forceFill(['status' => OfferStatus::Expired->value])->save();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/offers/{$offer->ulid}/reject")->assertOk();
    }

    // ------------------------------------------------------------ the waves

    public function test_a_closed_wave_lapses_its_cards_and_goes_out_wider(): void
    {
        $this->rider();

        $order = $this->acceptedOrder();

        app(OfferDispatchService::class)->closeWave($order->refresh(), 1);

        $this->assertSame(
            OfferStatus::Expired,
            RiderOffer::where('order_id', $order->id)->where('wave', 1)->firstOrFail()->status,
        );

        $this->assertSame(2, $order->fresh()->dispatch_wave);
        $this->assertSame(DispatchStatus::Searching->value, $order->fresh()->dispatch_status);
    }

    public function test_the_same_rider_is_not_rung_twice_for_one_order(): void
    {
        /*
         * A rider who ignored it thirty seconds ago is not more likely to
         * take it now, and three rings for one order is how a rider turns
         * notifications off — which costs every future order, not just this
         * one.
         */
        $rider = $this->rider();

        $order = $this->acceptedOrder();

        app(OfferDispatchService::class)->closeWave($order->refresh(), 1);

        $this->assertSame(1, RiderOffer::where('order_id', $order->id)->count());
    }

    public function test_the_last_wave_closing_with_nobody_lands_it_in_front_of_a_person(): void
    {
        /*
         * An order nobody took is food going cold with a customer watching a
         * spinner. The one thing that must not happen is for it to fail
         * quietly.
         */
        $order = $this->acceptedOrder();

        $dispatch = app(OfferDispatchService::class);

        $waves = count((array) config('dispatch.waves'));

        for ($wave = 1; $wave <= $waves; $wave++) {
            $dispatch->closeWave($order->refresh(), $wave);
        }

        $order->refresh();

        $this->assertSame(DispatchStatus::NoRiderFound->value, $order->dispatch_status);
        $this->assertNotNull($order->dispatch_failed_at);

        // And still not cancelled: the food exists and somebody has to decide
        // what happens to it.
        $this->assertSame(OrderStatus::Accepted, $order->status);
    }

    public function test_a_late_job_cannot_close_a_wave_that_has_moved_on(): void
    {
        /*
         * The queue backs up, the worker restarts, and a job for wave one
         * fires while wave two is out. Without the guard it would lapse the
         * live cards and restart the search on an order somebody is about to
         * accept.
         */
        $this->rider();

        $order = $this->acceptedOrder();

        $dispatch = app(OfferDispatchService::class);
        $dispatch->closeWave($order->refresh(), 1);

        $this->assertSame(2, $order->fresh()->dispatch_wave);

        // The stale job arrives.
        $dispatch->closeWave($order->refresh(), 1);

        $this->assertSame(2, $order->fresh()->dispatch_wave);
    }

    public function test_a_wave_job_for_an_order_a_rider_already_has_does_nothing(): void
    {
        $rider = $this->rider();

        $order = $this->acceptedOrder();
        $offer = RiderOffer::where('order_id', $order->id)->firstOrFail();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/offers/{$offer->ulid}/accept")->assertOk();

        app(OfferDispatchService::class)->closeWave($order->refresh(), 1);

        $this->assertSame(DispatchStatus::Assigned->value, $order->fresh()->dispatch_status);
        $this->assertSame($rider->id, $order->fresh()->rider_id);
    }

    public function test_three_ignored_offers_takes_a_rider_off_duty(): void
    {
        /*
         * Not a punishment — a phone at the bottom of a bag. Every wave that
         * includes a rider who is not there is thirty seconds the customer
         * waits for nothing, and dispatch has no other way to tell that rider
         * apart from one who is deciding.
         */
        $rider = $this->rider();

        $dispatch = app(OfferDispatchService::class);

        for ($i = 0; $i < 3; $i++) {
            $order = $this->acceptedOrder();

            $this->assertSame(1, RiderOffer::where('order_id', $order->id)->count(), "wave {$i} did not reach the rider");

            $dispatch->closeWave($order->refresh(), 1);

            // Freed up again for the next order, since the card lapsed.
            $rider->refresh();
        }

        $this->assertSame(RiderStatus::Offline, $rider->fresh()->duty_status);
    }

    public function test_the_acceptance_counters_follow_what_the_rider_did(): void
    {
        $rider = $this->rider();

        $first = $this->acceptedOrder();
        $offer = RiderOffer::where('order_id', $first->id)->firstOrFail();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/offers/{$offer->ulid}/accept")->assertOk();

        $rider->refresh();

        $this->assertSame(1, $rider->offers_received);
        $this->assertSame(1, $rider->offers_accepted);
        $this->assertSame(100.0, $rider->acceptanceRate());
    }

    public function test_a_rider_who_has_never_been_offered_anything_has_no_acceptance_rate(): void
    {
        // Zero would read as somebody who refuses everything, which is the
        // opposite of true and exactly the rider dispatch should be ringing.
        $this->assertNull($this->rider()->acceptanceRate());
    }

    // --------------------------------------------------------- current offer

    public function test_the_app_can_read_back_the_card_it_should_be_showing(): void
    {
        /*
         * A data message can be dropped by the system, arrive twice, or land
         * on a phone that was switched off. This is the truth about what is
         * ringing, and the app reads it on cold start and on resume.
         */
        $rider = $this->rider();

        $order = $this->acceptedOrder();

        Sanctum::actingAs($rider->user);

        $data = $this->getJson('/api/v1/rider/offers/current')->assertOk()->json('data');

        $this->assertSame($order->id, $data['order']['id']);
        $this->assertGreaterThan(0, $data['seconds_left']);
        $this->assertNotNull($data['expires_at']);
        $this->assertSame('Ponnusamy Hotel', $data['pickup']['name']);

        // Still no address, same as the push payload.
        $this->assertArrayNotHasKey('address', $data['dropoff']);
    }

    public function test_no_card_ringing_is_a_successful_answer(): void
    {
        // "You have no offer" is a question with an answer, not a 404.
        Sanctum::actingAs($this->rider()->user);

        $this->getJson('/api/v1/rider/offers/current')->assertOk()->assertJson(['data' => null]);
    }

    public function test_an_expired_card_is_not_read_back_as_live(): void
    {
        /*
         * A pending row whose window has passed is an offer nothing got round
         * to expiring yet — the queue was slow, the worker restarted. Serving
         * it would put a dead card back on a rider's screen.
         */
        $rider = $this->rider();

        $order = $this->acceptedOrder();

        RiderOffer::where('order_id', $order->id)->update(['expires_at' => now()->subMinute()]);

        Sanctum::actingAs($rider->user);

        $this->getJson('/api/v1/rider/offers/current')->assertOk()->assertJson(['data' => null]);
    }

    // ------------------------------------------------------- cancel and give back

    public function test_cancelling_an_order_stops_the_phones_ringing(): void
    {
        /*
         * A rider who accepts an offer for a cancelled order rides to a
         * restaurant that has thrown the food away.
         */
        $this->withDevice($this->rider());

        $order = $this->acceptedOrder();

        $sender = $this->recordingSender();

        Sanctum::actingAs($order->merchant->user);
        $this->postJson("/api/v1/merchant/orders/{$order->id}/cancel", [
            'reason' => 'The gas ran out.',
        ])->assertOk();

        $this->assertNotNull($this->payloadOfType($sender, 'OFFER_CANCELLED'));

        $this->assertSame(
            OfferStatus::Cancelled,
            RiderOffer::where('order_id', $order->id)->firstOrFail()->status,
        );
    }

    public function test_a_rider_handing_an_order_back_puts_it_out_again(): void
    {
        /*
         * On the board this needed nothing — the order reappeared on a list
         * people were already polling. With offers, nobody is looking at
         * anything, so an order handed back and not re-offered stops existing
         * until a human notices.
         */
        $first = $this->rider();
        $second = $this->rider();

        $order = $this->acceptedOrder();

        $offer = RiderOffer::where('order_id', $order->id)->where('rider_id', $first->id)->firstOrFail();

        Sanctum::actingAs($first->user);
        $this->postJson("/api/v1/rider/offers/{$offer->ulid}/accept")->assertOk();
        $this->postJson("/api/v1/rider/orders/{$order->id}/release", ['reason' => 'Puncture'])->assertOk();

        $order->refresh();

        $this->assertNull($order->rider_id);
        $this->assertSame(DispatchStatus::Searching->value, $order->dispatch_status);

        // Including back to the rider who gave it up, because time has passed
        // and the reason they could not take it may have gone with it.
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            RiderOffer::where('order_id', $order->id)->pluck('rider_id')->all(),
        );
    }

    public function test_handing_back_an_order_still_being_cooked_leaves_the_kitchen_alone(): void
    {
        // The release path that the board never had to deal with: there is no
        // status to walk back, because the kitchen never stopped.
        $rider = $this->rider();

        $order = $this->acceptedOrder();
        $offer = RiderOffer::where('order_id', $order->id)->firstOrFail();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/offers/{$offer->ulid}/accept")->assertOk();
        $this->postJson("/api/v1/rider/orders/{$order->id}/release")->assertOk();

        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        $this->assertNull($order->fresh()->rider_id);

        // And it is on the record, which is the only evidence of why a
        // delivery took twice as long as it should have.
        $this->assertTrue(
            $order->statusHistory()->where('note', 'Handed back by the rider.')->exists(),
        );
    }

    // ----------------------------------------------------------- arrival steps

    public function test_the_rider_can_say_they_are_at_the_restaurant_and_at_the_door(): void
    {
        $rider = $this->rider();
        $order = $this->readyOrder();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        $this->postJson("/api/v1/rider/orders/{$order->id}/reached-restaurant")->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::ReachedRestaurant, $order->status);
        $this->assertNotNull($order->reached_restaurant_at);
        // Waiting pay starts from the earlier of the two, so this is stamped
        // as well if nothing else has stamped it.
        $this->assertNotNull($order->arrived_at);

        $this->postJson("/api/v1/rider/orders/{$order->id}/pickup", [
            'pickup_code' => $order->pickup_code,
        ])->assertOk();

        $this->postJson("/api/v1/rider/orders/{$order->id}/reached-customer")->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::ReachedCustomer, $order->status);
        $this->assertNotNull($order->reached_customer_at);

        $this->postJson("/api/v1/rider/orders/{$order->id}/deliver")->assertOk();

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_a_rider_who_skips_the_arrival_buttons_can_still_collect_and_deliver(): void
    {
        // A rider holding a bag must never be blocked by a button they did
        // not press.
        $rider = $this->rider();
        $order = $this->readyOrder();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        $this->postJson("/api/v1/rider/orders/{$order->id}/pickup", [
            'pickup_code' => $order->pickup_code,
        ])->assertOk();

        $this->postJson("/api/v1/rider/orders/{$order->id}/deliver")->assertOk();

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_another_riders_order_cannot_be_marked_arrived(): void
    {
        $mine = $this->rider();
        $theirs = $this->rider();

        $order = $this->readyOrder();

        Sanctum::actingAs($theirs->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        Sanctum::actingAs($mine->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/reached-restaurant")->assertNotFound();
    }

    // ------------------------------------------------------------- mock GPS

    public function test_a_faked_position_is_refused(): void
    {
        /*
         * A rider sitting at home moving a pin down a road would otherwise be
         * put into dispatch near a restaurant they are nowhere near, and paid
         * a first mile they never rode.
         */
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);

        $this->postJson('/api/v1/rider/location', [
            'latitude' => 9.9195,
            'longitude' => 78.1193,
            'is_mock' => true,
        ])->assertStatus(422)->assertJsonPath('data.mock_location', true);

        $this->postJson('/api/v1/rider/location', [
            'latitude' => 9.9195,
            'longitude' => 78.1193,
            'is_mock' => false,
        ])->assertOk()->assertJsonPath('data.accepted', true);
    }

    // ------------------------------------------------------------ board mode

    public function test_board_mode_rings_nobody(): void
    {
        /*
         * The modes have to be able to coexist, because push does not work
         * without a queue worker and the board does. Switching back must not
         * leave half a feature running.
         */
        config(['dispatch.mode' => 'board']);

        $this->withDevice($this->rider());

        $sender = $this->recordingSender();

        $order = $this->acceptedOrder();

        $this->assertSame(0, RiderOffer::where('order_id', $order->id)->count());
        $this->assertNull($this->payloadOfType($sender, 'ORDER_OFFER'));

        Queue::assertNotPushed(CloseDispatchWave::class);

        // The dispatch state is still recorded, so the customer's "finding a
        // delivery partner" reads one field in both modes.
        $this->assertSame(DispatchStatus::Searching->value, $order->fresh()->dispatch_status);
    }

    // ------------------------------------------------------------- ops escape

    public function test_ops_can_see_an_order_dispatch_gave_up_on(): void
    {
        $order = $this->acceptedOrder();

        $dispatch = app(OfferDispatchService::class);

        foreach (array_keys((array) config('dispatch.waves')) as $index) {
            $dispatch->closeWave($order->refresh(), $index + 1);
        }

        Sanctum::actingAs($this->admin());

        $data = $this->getJson('/api/v1/admin/dispatch/stranded')->assertOk()->json();

        $this->assertSame($order->id, $data['data'][0]['id']);
        $this->assertSame(DispatchStatus::NoRiderFound->value, $data['data'][0]['dispatch_status']);
        // Said out loud, because an empty rider pool and a dead queue worker
        // produce the same symptom and have different fixes.
        $this->assertArrayHasKey('riders_on_duty', $data['meta']);
    }

    public function test_ops_can_hand_a_stranded_order_to_a_named_rider(): void
    {
        $rider = $this->rider();
        $order = $this->readyOrder();

        $order->forceFill([
            'dispatch_status' => DispatchStatus::NoRiderFound->value,
            'dispatch_failed_at' => now(),
        ])->save();

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/dispatch/{$order->id}/assign", [
            'rider_id' => $rider->id,
        ])->assertOk();

        $order->refresh();

        $this->assertSame($rider->id, $order->rider_id);
        $this->assertSame(OrderStatus::RiderAssigned, $order->status);
        $this->assertNull($order->dispatch_failed_at);
        $this->assertSame(RiderStatus::OnOrder, $rider->fresh()->duty_status);
    }

    public function test_ops_cannot_hand_an_order_to_a_rider_whose_licence_has_expired(): void
    {
        // Nobody can be handed a delivery on expired paperwork, including by
        // an admin — that one is a legal problem, not a policy.
        $rider = $this->rider(['driving_licence_expiry' => now()->subDay()]);
        $order = $this->readyOrder();

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/dispatch/{$order->id}/assign", [
            'rider_id' => $rider->id,
        ])->assertStatus(422)->assertJsonValidationErrors('rider_id');
    }

    public function test_ops_can_put_a_stranded_order_back_out(): void
    {
        $order = $this->acceptedOrder();

        $dispatch = app(OfferDispatchService::class);

        foreach (array_keys((array) config('dispatch.waves')) as $index) {
            $dispatch->closeWave($order->refresh(), $index + 1);
        }

        // A rider comes on duty after dispatch had already given up.
        $rider = $this->rider();

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/dispatch/{$order->id}/retry")->assertOk();

        $this->assertSame(DispatchStatus::Searching->value, $order->fresh()->dispatch_status);
        $this->assertSame(1, $order->fresh()->dispatch_wave);
        $this->assertTrue(RiderOffer::where('order_id', $order->id)->where('rider_id', $rider->id)->exists());
    }

    public function test_ops_cannot_reassign_an_order_a_rider_already_has(): void
    {
        $holder = $this->rider();
        $other = $this->rider();
        $order = $this->readyOrder();

        Sanctum::actingAs($holder->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/dispatch/{$order->id}/assign", [
            'rider_id' => $other->id,
        ])->assertStatus(422);
    }
}
