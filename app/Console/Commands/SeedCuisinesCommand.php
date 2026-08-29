<?php

namespace App\Console\Commands;

use App\Models\Cuisine;
use App\Services\Media\ImageService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Load the cuisine rail from the bundled set.
 *
 * Sixty cuisines through a web form is an afternoon of typing and a guaranteed
 * typo in a slug — and a slug that matches nothing filters to nothing,
 * silently. This ships the names, the order and the icons together.
 *
 * Safe to run twice. An existing cuisine keeps its name and position unless
 * --force is given, and keeps its icon unless it has none.
 */
class SeedCuisinesCommand extends Command
{
    protected $signature = 'nexmile:seed-cuisines
                            {--force : Overwrite names, positions and icons that already exist}
                            {--only= : Comma-separated slugs, when you want a few rather than all}';

    protected $description = 'Create the cuisine rail from the bundled icons';

    public function handle(ImageService $images): int
    {
        $dir = database_path('seeders/cuisines');
        $manifest = $dir.'/manifest.json';

        if (! is_file($manifest)) {
            $this->error("No manifest at {$manifest}.");

            return self::FAILURE;
        }

        $wanted = $this->option('only') === null
            ? null
            : array_map('trim', explode(',', (string) $this->option('only')));

        $rows = json_decode(file_get_contents($manifest), true);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if ($wanted !== null && ! in_array($row['slug'], $wanted, true)) {
                continue;
            }

            $cuisine = Cuisine::firstOrNew(['slug' => $row['slug']]);
            $isNew = ! $cuisine->exists;

            if ($isNew || $this->option('force')) {
                $cuisine->fill([
                    'name' => $row['name'],
                    'position' => $row['position'],
                    'is_active' => true,
                ]);
            }

            $cuisine->save();

            /*
             * The icon is the reason this command exists — every cuisine was
             * reaching the app with image_url null. Replaced only when missing,
             * or when --force says so, since an admin may have uploaded a
             * better one by hand.
             */
            $icon = $dir.'/'.$row['slug'].'.png';
            $needsIcon = $cuisine->image_path === null || $cuisine->image_path === '';

            if (is_file($icon) && ($needsIcon || $this->option('force'))) {
                $images->attach(
                    $cuisine,
                    'image_path',
                    'cuisines',
                    // A copy, because UploadedFile moves the file it is given
                    // and the seed set has to survive the next run.
                    new UploadedFile($icon, $row['slug'].'.png', 'image/png', null, true),
                );
            }

            match (true) {
                $isNew => $created++,
                $needsIcon || (bool) $this->option('force') => $updated++,
                default => $skipped++,
            };
        }

        $this->line('');
        $this->line("  <fg=green;options=bold>{$created}</> created, <fg=yellow>{$updated}</> updated, <fg=gray>{$skipped}</> already fine.");
        $this->line('');
        $this->line('  <fg=gray>A restaurant appears under a cuisine when the same slug is on its</>');
        $this->line('  <fg=gray>profile — set that under Store, Listing and filters.</>');
        $this->line('');

        return self::SUCCESS;
    }
}
