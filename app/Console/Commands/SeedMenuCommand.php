<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Services\Media\ImageService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Build a restaurant a full menu.
 *
 * Forty-eight dishes through a web form is a day of typing, and a menu with
 * three dishes on it tests nothing — no category rail, no price filter worth
 * applying, no search that finds anything.
 *
 * Prices are Tier-2 realistic so the seeded menu can be ordered from without
 * anyone editing forty-eight numbers first, and GST is 5% throughout, which is
 * what the rest of the app assumes for prepared food.
 */
class SeedMenuCommand extends Command
{
    protected $signature = 'nexmile:seed-menu
                            {merchant : Merchant id, business name, or account email}
                            {--force : Overwrite dishes that already exist}
                            {--only= : Comma-separated category names}
                            {--dry-run : List what would be created and stop}';

    protected $description = 'Give a restaurant a full menu with dish photos';

    /** Prepared food. The other rates exist for packaged goods. */
    private const GST_RATE = 5.00;

    public function handle(ImageService $images): int
    {
        $merchant = $this->resolve($this->argument('merchant'));

        if ($merchant === null) {
            $this->error('No merchant matches that id, name or email.');

            return self::FAILURE;
        }

        $dir = database_path('seeders/menu');
        $manifest = $dir.'/manifest.json';

        if (! is_file($manifest)) {
            $this->error("No manifest at {$manifest}.");

            return self::FAILURE;
        }

        $groups = json_decode(file_get_contents($manifest), true);

        $only = $this->option('only') === null
            ? null
            : array_map('trim', explode(',', (string) $this->option('only')));

        $this->line('');
        $this->line("  <options=bold>{$merchant->business_name}</>  (id {$merchant->id})");
        $this->line('');

        $created = 0;
        $skipped = 0;
        $updated = 0;
        $refused = 0;

        foreach ($groups as $group) {
            if ($only !== null && ! in_array($group['category'], $only, true)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  <fg=gray>{$group['category']}</> — ".count($group['dishes']).' dishes');

                continue;
            }

            $category = Category::firstOrCreate(
                ['merchant_id' => $merchant->id, 'name' => $group['category']],
                ['sort_order' => $group['sort_order'], 'is_active' => true],
            );

            foreach ($group['dishes'] as $dish) {
                /*
                 * Matched on name within this merchant, not on a slug — the
                 * slug is ours and a merchant may have renamed the dish. This
                 * keeps a second run from duplicating a menu.
                 */
                $item = MenuItem::firstOrNew([
                    'merchant_id' => $merchant->id,
                    'name' => $dish['name'],
                ]);

                $isNew = ! $item->exists;

                if (! $isNew && ! $this->option('force')) {
                    $skipped++;

                    continue;
                }

                /*
                 * The same rule the portal enforces. A command writing
                 * straight to the model would otherwise walk around a guard
                 * the forms hold, and leave a pure veg kitchen selling mutton
                 * biryani — which is exactly the contradiction that makes the
                 * veg filter worthless.
                 */
                if ($merchant->is_pure_veg && ! $dish['is_veg']) {
                    $refused++;

                    continue;
                }

                $item->fill([
                    'category_id' => $category->id,
                    'description' => $dish['description'],
                    'price' => $dish['price'],
                    'gst_rate' => self::GST_RATE,
                    'is_veg' => $dish['is_veg'],
                    'is_available' => true,
                    'sort_order' => $dish['sort_order'],
                ])->save();

                // WebP: transparent, and a fifth the size of the equivalent PNG at this
                // resolution. Flutter renders it natively.
                $photo = $dir.'/'.$dish['slug'].'.webp';

                if (is_file($photo) && ($item->image_path === null || $this->option('force'))) {
                    $images->attach(
                        $item,
                        'image_path',
                        $item->photoDirectory(),
                        // Copied, not moved: the seed set has to survive the
                        // next run and the next merchant.
                        new UploadedFile($photo, $dish['slug'].'.webp', 'image/webp', null, true),
                    );
                }

                $isNew ? $created++ : $updated++;
            }
        }

        if ($this->option('dry-run')) {
            $this->line('');
            $this->line('  <fg=gray>Dry run — nothing was written.</>');
            $this->line('');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line("  <fg=green;options=bold>{$created}</> dishes created, <fg=yellow>{$updated}</> updated, <fg=gray>{$skipped}</> already there.");
        $this->line('');

        /*
         * A menu is not enough on its own. A pure veg kitchen refuses the
         * non-veg half of this, and a restaurant with no cuisines set never
         * appears under a cuisine tile however good its menu is.
         */
        if ($refused > 0) {
            $this->line("  <fg=yellow>{$refused} non-veg dishes were skipped: this kitchen is marked pure</>");
            $this->line('  <fg=yellow>vegetarian. Untick that under Store if it is wrong.</>');
            $this->line('');
        }

        if (empty($merchant->cuisines)) {
            $this->line('  <fg=gray>No cuisines set — it will not appear under any cuisine tile.</>');
            $this->line('  <fg=gray>Set them under Store, Listing and filters.</>');
            $this->line('');
        }

        return self::SUCCESS;
    }

    private function resolve(string $term): ?Merchant
    {
        if (ctype_digit($term)) {
            return Merchant::find((int) $term);
        }

        return Merchant::where('business_name', 'like', "%{$term}%")
            ->orWhereHas('user', fn ($q) => $q->where('email', $term))
            ->first();
    }
}
