<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The storefront photo carousel.
 *
 * One banner heads a page; it does not sell a place. A customer deciding
 * between two kitchens they have never visited is deciding from these.
 */
class StorefrontPhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('media.disk'));
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
    }

    private function merchantUser(): User
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Owner '.$n,
            'phone' => '97700000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "gallery{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        Merchant::create([
            'user_id' => $user->id,
            'business_name' => 'Kishore Food '.$n,
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'latitude' => 9.9195,
            'longitude' => 78.1193,
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
        ]);

        return $user->fresh();
    }

    private function addPhoto(User $user, ?string $caption = null): void
    {
        $this->actingAs($user)
            ->post('/merchants/storefront/photos', array_filter([
                'file' => $this->png(),
                'caption' => $caption,
            ]))
            ->assertRedirect();
    }

    public function test_a_merchant_can_add_photos_to_their_carousel(): void
    {
        $user = $this->merchantUser();

        $this->addPhoto($user, 'Our dining area');

        $photo = $user->merchant->photos()->sole();

        $this->assertSame('Our dining area', $photo->caption);
        $this->assertNotNull($photo->image_path);
        Storage::disk(config('media.disk'))->assertExists($photo->image_path);
    }

    public function test_photos_are_appended_not_inserted(): void
    {
        // A new photo joining the middle of a carousel the merchant already
        // arranged is a surprise.
        $user = $this->merchantUser();

        $this->addPhoto($user, 'First');
        $this->addPhoto($user, 'Second');
        $this->addPhoto($user, 'Third');

        $this->assertSame(
            ['First', 'Second', 'Third'],
            $user->merchant->photos()->pluck('caption')->all(),
        );
    }

    public function test_a_photo_can_be_moved_earlier(): void
    {
        // Which photo leads is the only ordering decision that really matters.
        $user = $this->merchantUser();

        $this->addPhoto($user, 'First');
        $this->addPhoto($user, 'Second');

        $second = $user->merchant->photos()->where('caption', 'Second')->sole();

        $this->actingAs($user)
            ->post("/merchants/storefront/photos/{$second->id}/move", ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame(
            ['Second', 'First'],
            $user->merchant->photos()->pluck('caption')->all(),
        );
    }

    public function test_moving_past_either_end_does_nothing(): void
    {
        $user = $this->merchantUser();

        $this->addPhoto($user, 'Only');

        $photo = $user->merchant->photos()->sole();

        foreach (['up', 'down'] as $direction) {
            $this->actingAs($user)
                ->post("/merchants/storefront/photos/{$photo->id}/move", ['direction' => $direction])
                ->assertRedirect();
        }

        $this->assertSame(['Only'], $user->merchant->photos()->pluck('caption')->all());
    }

    public function test_reordering_survives_a_deletion_in_the_middle(): void
    {
        /*
         * Positions drift after a delete, and two rows can end up sharing one.
         * Swapping a pair of duplicates does nothing and reads as a broken
         * button, so the whole sequence is renumbered on every move.
         */
        $user = $this->merchantUser();

        foreach (['A', 'B', 'C'] as $caption) {
            $this->addPhoto($user, $caption);
        }

        $b = $user->merchant->photos()->where('caption', 'B')->sole();

        $this->actingAs($user)->delete("/merchants/storefront/photos/{$b->id}")->assertRedirect();

        $c = $user->merchant->photos()->where('caption', 'C')->sole();

        $this->actingAs($user)
            ->post("/merchants/storefront/photos/{$c->id}/move", ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame(['C', 'A'], $user->merchant->photos()->pluck('caption')->all());
    }

    public function test_removing_a_photo_deletes_the_file(): void
    {
        // An orphaned object is a bill nobody is watching.
        $user = $this->merchantUser();

        $this->addPhoto($user);

        $photo = $user->merchant->photos()->sole();
        $stored = $photo->image_path;

        $this->actingAs($user)
            ->delete("/merchants/storefront/photos/{$photo->id}")
            ->assertRedirect();

        $this->assertSame(0, $user->merchant->photos()->count());
        Storage::disk(config('media.disk'))->assertMissing($stored);
    }

    public function test_the_carousel_is_capped(): void
    {
        // Every slide is a signed URL the app fetches when the page opens.
        $user = $this->merchantUser();
        $limit = (int) config('media.max_storefront_photos');

        for ($i = 0; $i < $limit; $i++) {
            $this->addPhoto($user, 'Photo '.$i);
        }

        $this->actingAs($user)
            ->post('/merchants/storefront/photos', ['file' => $this->png()])
            ->assertSessionHasErrors('file');

        $this->assertSame($limit, $user->merchant->photos()->count());
    }

    public function test_another_merchants_photo_cannot_be_touched(): void
    {
        $theirs = $this->merchantUser();
        $this->addPhoto($theirs, 'Theirs');

        $photo = $theirs->merchant->photos()->sole();
        $other = $this->merchantUser();

        $this->actingAs($other)
            ->delete("/merchants/storefront/photos/{$photo->id}")
            ->assertNotFound();

        $this->actingAs($other)
            ->post("/merchants/storefront/photos/{$photo->id}/move", ['direction' => 'up'])
            ->assertNotFound();

        $this->assertSame(1, $theirs->merchant->photos()->count());
    }

    public function test_the_storefront_endpoint_returns_the_gallery_in_order(): void
    {
        $user = $this->merchantUser();

        $this->addPhoto($user, 'First');
        $this->addPhoto($user, 'Second');

        Sanctum::actingAs($this->customer());

        $response = $this->getJson("/api/v1/restaurants/{$user->merchant->id}")->assertOk();

        $response->assertJsonCount(2, 'data.photos')
            ->assertJsonPath('data.photos.0.caption', 'First');

        $this->assertNotNull($response->json('data.photos.0.url'));
    }

    public function test_the_nearby_list_does_not_carry_galleries(): void
    {
        /*
         * Twenty restaurants with eight signed URLs each is a hundred and
         * sixty links generated for images nobody will scroll to.
         */
        $user = $this->merchantUser();
        $this->addPhoto($user, 'First');

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/restaurants?latitude=9.9195&longitude=78.1193')
            ->assertOk()
            ->assertJsonMissingPath('data.0.photos');
    }

    private function customer(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Customer '.$n,
            'phone' => '90700000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "gallerycust{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }
}
