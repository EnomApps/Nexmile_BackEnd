<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\RiderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Address;
use App\Models\Order;
use App\Models\Rider;
use App\Models\User;
use App\Services\Orders\OfferDispatchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * A photo of the customer's door, and who is allowed to see it.
 *
 * The access question is the whole point of these tests. The photo plus the
 * coordinates sitting beside it in the same response answers "where does this
 * person live", so the rules about who gets it are not a detail.
 */
class DoorPhotoTest extends CartTest
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['media.disk' => 's3']);
    }

    /** @param array<string, mixed> $attributes */
    private function address(User $user, array $attributes = []): Address
    {
        return $user->addresses()->create([
            'label' => 'home',
            'contact_name' => 'Meena',
            'contact_phone' => '9876543210',
            'line1' => 'Flat 3B, second floor',
            'landmark' => 'Opposite the water tank',
            'city' => 'Madurai',
            'pincode' => '625020',
            'latitude' => 9.9200,
            'longitude' => 78.1195,
            ...$attributes,
        ]);
    }

    /**
     * `create` with a stated mime rather than `image()`, which needs GD —
     * absent from at least one machine this suite runs on. Same convention as
     * MerchantMenuTest.
     */
    private function photo(string $name = 'door.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 220, 'image/jpeg');
    }

    /**
     * A multipart upload that still answers in JSON.
     *
     * Without `Accept: application/json` a failed validation is a 302 back to
     * a form, which is useless to an app. The apps send the header on every
     * call, so the tests do too.
     *
     * @param  array<string, mixed>  $payload
     */
    private function upload(int $addressId, array $payload): TestResponse
    {
        return $this->post(
            "/api/v1/addresses/{$addressId}/photo",
            $payload,
            ['Accept' => 'application/json'],
        );
    }

    private function rider(): Rider
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Rider '.$n,
            'phone' => '97930000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "doorrider{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Rider,
            'status' => UserStatus::Active,
        ]);

        return $user->rider()->create([
            'full_name' => 'Selvam K',
            'vehicle_type' => 'motorcycle',
            'vehicle_number' => 'TN59CD'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'kyc_status' => KycStatus::Verified,
            'kyc_verified_at' => now(),
            'driving_licence_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
            'duty_status' => RiderStatus::Available,
            'last_latitude' => 9.9195,
            'last_longitude' => 78.1193,
            'last_location_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Uploading
    |--------------------------------------------------------------------------
    */

    public function test_a_customer_can_add_a_door_photo_to_their_own_address(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $address = $this->address($user);

        $body = $this->upload($address->id, ['photo' => $this->photo()])
            ->assertOk()
            ->json('data');

        $this->assertSame($address->id, $body['id']);
        $this->assertNotNull($body['photo_url']);

        $path = $address->fresh()->photo_path;
        $this->assertNotNull($path);
        Storage::disk('s3')->assertExists($path);

        // Filed under the owner, so a stray object is traceable to a person.
        $this->assertStringStartsWith("door-photos/{$user->id}/", $path);
    }

    public function test_the_uploaded_filename_is_discarded(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $address = $this->address($user);

        $this->upload($address->id, [
            'photo' => $this->photo('../../etc/passwd.jpg'),
        ])->assertOk();

        // A user-supplied name can carry path separators and a second
        // extension. ImageService replaces it with a UUID.
        $this->assertStringNotContainsString('passwd', (string) $address->fresh()->photo_path);
    }

    public function test_an_address_without_a_photo_reports_a_null_url(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $this->address($user);

        $this->getJson('/api/v1/addresses')
            ->assertOk()
            ->assertJsonPath('data.0.photo_url', null);
    }

    public function test_a_second_upload_replaces_the_first(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $address = $this->address($user);

        $this->upload($address->id, ['photo' => $this->photo()])->assertOk();
        $first = $address->fresh()->photo_path;

        $this->upload($address->id, ['photo' => $this->photo('new.jpg')])->assertOk();
        $second = $address->fresh()->photo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('s3')->assertExists($second);
        Storage::disk('s3')->assertMissing($first);
    }

    public function test_a_customer_can_remove_the_photo(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $address = $this->address($user);

        $this->upload($address->id, ['photo' => $this->photo()])->assertOk();
        $path = $address->fresh()->photo_path;

        $this->deleteJson("/api/v1/addresses/{$address->id}/photo")
            ->assertOk()
            ->assertJsonPath('data.photo_url', null);

        $this->assertNull($address->fresh()->photo_path);
        Storage::disk('s3')->assertMissing($path);

        // The address itself survives — only the photo was removed.
        $this->assertNotNull(Address::find($address->id));
    }

    public function test_a_bad_file_is_refused_under_the_photo_key(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $address = $this->address($user);

        $this->upload($address->id, [
            'photo' => UploadedFile::fake()->create('door.pdf', 20, 'application/pdf'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');

        $this->assertNull($address->fresh()->photo_path);
    }

    public function test_an_upload_with_no_file_is_refused(): void
    {
        Sanctum::actingAs($user = $this->customer());
        $address = $this->address($user);

        $this->postJson("/api/v1/addresses/{$address->id}/photo", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');
    }

    /*
    |--------------------------------------------------------------------------
    | Who it belongs to
    |--------------------------------------------------------------------------
    */

    public function test_a_customer_cannot_photograph_someone_elses_address(): void
    {
        $theirs = $this->address($this->customer());

        Sanctum::actingAs($this->customer());

        // 404, not 403: a 403 would confirm the address exists.
        $this->upload($theirs->id, ['photo' => $this->photo()])
            ->assertStatus(404);
        $this->deleteJson("/api/v1/addresses/{$theirs->id}/photo")
            ->assertStatus(404);

        $this->assertNull($theirs->fresh()->photo_path);
    }

    public function test_a_signed_out_request_is_refused(): void
    {
        $address = $this->address($this->customer());

        $this->postJson("/api/v1/addresses/{$address->id}/photo", [])->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | Reaching the rider
    |--------------------------------------------------------------------------
    */

    private function placedOrder(User $customer, Address $address): Order
    {
        $shop = $this->restaurant();
        $dish = $this->dish($shop);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery',
            'payment_method' => 'cod',
            'address_id' => $address->id,
        ])->assertCreated();

        return Order::sole();
    }

    public function test_the_order_keeps_its_own_copy_of_the_photo(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $address = $this->address($customer);

        $this->upload($address->id, ['photo' => $this->photo()])->assertOk();
        $original = $address->fresh()->photo_path;

        $order = $this->placedOrder($customer, $address);

        $this->assertSame($original, $order->delivery_photo_path);
    }

    public function test_replacing_the_photo_does_not_blank_a_live_delivery(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $address = $this->address($customer);

        $this->upload($address->id, ['photo' => $this->photo()])->assertOk();
        $original = $address->fresh()->photo_path;

        $order = $this->placedOrder($customer, $address);

        // The customer moves house mid-delivery and uploads a new door.
        $this->upload($address->id, ['photo' => $this->photo('other.jpg')])->assertOk();

        // The order still points at the first photo, and that object is still
        // there — the rider at the gate is looking at it.
        $this->assertSame($original, $order->fresh()->delivery_photo_path);
        Storage::disk('s3')->assertExists($original);
    }

    public function test_the_assigned_rider_sees_the_receiver_and_the_door(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $address = $this->address($customer, [
            'contact_name' => 'Amudha',
            'contact_phone' => '9123456780',
        ]);

        $this->upload($address->id, ['photo' => $this->photo()])->assertOk();
        $order = $this->placedOrder($customer, $address);

        $rider = $this->rider();
        $order->forceFill(['rider_id' => $rider->id, 'assigned_at' => now()])->save();

        Sanctum::actingAs($rider->user);
        $dropoff = $this->getJson("/api/v1/rider/orders/{$order->id}")
            ->assertOk()
            ->json('data.dropoff');

        // Whoever is actually taking the delivery, not the account holder.
        $this->assertSame('Amudha', $dropoff['contact_name']);
        $this->assertSame('9123456780', $dropoff['contact_phone']);

        // The two lines a rider reads at the gate, on their own.
        $this->assertSame('Flat 3B, second floor', $dropoff['line1']);
        $this->assertSame('Opposite the water tank', $dropoff['landmark']);

        $this->assertNotNull($dropoff['photo_url']);
    }

    public function test_an_unassigned_order_hands_the_door_to_nobody(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $address = $this->address($customer);

        $this->upload($address->id, ['photo' => $this->photo()])->assertOk();
        $order = $this->placedOrder($customer, $address);

        $rider = $this->rider();
        Sanctum::actingAs($rider->user);

        /*
         * The board is pollable by anyone on duty. If an unassigned order
         * carried the drop address, the board would double as a list of where
         * customers live — with a photo of each front door attached.
         */
        $body = $this->getJson('/api/v1/rider/orders/available')->assertOk()->json();
        $encoded = json_encode($body);

        $this->assertStringNotContainsString('door-photos', (string) $encoded);
        $this->assertStringNotContainsString('Opposite the water tank', (string) $encoded);
        $this->assertStringNotContainsString('9876543210', (string) $encoded);
    }

    public function test_the_ringing_offer_card_does_not_carry_the_door(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $address = $this->address($customer);

        $this->upload($address->id, ['photo' => $this->photo()])->assertOk();
        $order = $this->placedOrder($customer, $address);

        $rider = $this->rider();
        $offer = $order->offers()->create([
            'rider_id' => $rider->id,
            'wave' => 1,
            'distance_metres' => 400,
            'offered_at' => now(),
            'expires_at' => now()->addSeconds(30),
        ]);

        $payload = app(OfferDispatchService::class)
            ->offerPayload($order->fresh(), $offer);

        /*
         * An offer goes to up to fifteen riders a wave and most of them
         * decline. The card says which restaurant and roughly how far, which
         * is what a rider needs to say yes or no; the customer's name, number,
         * flat and front door are for whoever actually takes the job.
         */
        $flat = json_encode($payload);
        $this->assertStringNotContainsString('door-photos', (string) $flat);
        $this->assertStringNotContainsString('Opposite the water tank', (string) $flat);
        $this->assertStringNotContainsString('9876543210', (string) $flat);
        $this->assertArrayNotHasKey('photo_url', $payload);
        $this->assertArrayNotHasKey('contact_phone', $payload);
    }
}
