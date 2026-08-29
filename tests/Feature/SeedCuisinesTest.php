<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The bundled cuisine rail.
 *
 * Sixty cuisines through a web form is an afternoon of typing and a guaranteed
 * typo in a slug — and a slug that matches nothing filters to nothing,
 * silently.
 */
class SeedCuisinesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('media.disk'));
    }

    public function test_it_creates_cuisines_with_their_icons(): void
    {
        $this->artisan('nexmile:seed-cuisines', ['--only' => 'biryani,dosa'])
            ->assertSuccessful();

        $biryani = Cuisine::where('slug', 'biryani')->sole();

        $this->assertSame('Biryani', $biryani->name);
        $this->assertTrue($biryani->is_active);
        $this->assertNotNull($biryani->image_path);

        // The whole point: this is what reached the app as null.
        Storage::disk(config('media.disk'))->assertExists($biryani->image_path);
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        // It will be run again after every deploy that adds a cuisine, so a
        // second run must not duplicate rows or re-upload every icon.
        $this->artisan('nexmile:seed-cuisines', ['--only' => 'biryani'])->assertSuccessful();

        $before = Cuisine::where('slug', 'biryani')->sole();

        $this->artisan('nexmile:seed-cuisines', ['--only' => 'biryani'])->assertSuccessful();

        $after = Cuisine::where('slug', 'biryani')->sole();

        $this->assertSame(1, Cuisine::where('slug', 'biryani')->count());
        $this->assertSame($before->image_path, $after->image_path);
    }

    public function test_it_leaves_an_icon_an_admin_uploaded_alone(): void
    {
        /*
         * An admin may have replaced a bundled icon with a better one. Seeding
         * again must not quietly undo that — only --force may.
         */
        $this->artisan('nexmile:seed-cuisines', ['--only' => 'dosa'])->assertSuccessful();

        $cuisine = Cuisine::where('slug', 'dosa')->sole();
        $cuisine->forceFill(['image_path' => 'cuisines/hand-picked.png', 'name' => 'Dosa & Uthappam'])->save();

        $this->artisan('nexmile:seed-cuisines', ['--only' => 'dosa'])->assertSuccessful();

        $fresh = $cuisine->fresh();

        $this->assertSame('cuisines/hand-picked.png', $fresh->image_path);
        $this->assertSame('Dosa & Uthappam', $fresh->name);
    }

    public function test_force_replaces_what_is_there(): void
    {
        $this->artisan('nexmile:seed-cuisines', ['--only' => 'dosa'])->assertSuccessful();

        $cuisine = Cuisine::where('slug', 'dosa')->sole();
        $cuisine->forceFill(['name' => 'Wrong'])->save();

        $this->artisan('nexmile:seed-cuisines', ['--only' => 'dosa', '--force' => true])
            ->assertSuccessful();

        $this->assertSame('Dosa', $cuisine->fresh()->name);
    }

    public function test_the_bundled_set_survives_being_seeded(): void
    {
        /*
         * UploadedFile moves the file it is given unless told otherwise. If
         * that ever regresses, the first seed on a fresh server would empty
         * the folder and the second would silently create iconless cuisines.
         */
        $dir = database_path('seeders/cuisines');
        $before = count(glob($dir.'/*.png'));

        $this->artisan('nexmile:seed-cuisines', ['--only' => 'biryani,dosa,cake'])->assertSuccessful();

        $this->assertSame($before, count(glob($dir.'/*.png')));
    }

    public function test_every_manifest_entry_has_an_icon_file(): void
    {
        // A missing file would create a cuisine with no icon — exactly the
        // state this command exists to fix.
        $dir = database_path('seeders/cuisines');
        $rows = json_decode(file_get_contents($dir.'/manifest.json'), true);

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertFileExists($dir.'/'.$row['slug'].'.png', "{$row['slug']} has no icon");
        }
    }

    public function test_slugs_are_in_the_shape_the_app_sends_back(): void
    {
        // Lowercase and hyphens, matching what the admin form enforces and
        // what a restaurant's cuisines list holds.
        $rows = json_decode(file_get_contents(database_path('seeders/cuisines/manifest.json')), true);

        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $row['slug']);
        }
    }
}
