<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Services\Media\ImageService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Fill a restaurant's carousel with clearly-marked samples.
 *
 * A storefront with no photos shows a single banner and nothing else, which
 * gives an app developer nothing to build a carousel against and a customer
 * nothing to look at.
 *
 * These are placeholders and say so on the image itself. The gallery is the
 * one place a generated graphic is genuinely weak — its whole job is showing
 * someone a room they have never stood in — so every slide carries "replace
 * with your own photo" and the captions are neutral: "Dining area", never
 * "Our dining area".
 */
class SeedStorefrontPhotosCommand extends Command
{
    protected $signature = 'nexmile:seed-photos
                            {merchant : Merchant id, business name, or account email}
                            {--replace : Remove existing photos first}';

    protected $description = 'Fill a restaurant carousel with sample photos';

    public function handle(ImageService $images): int
    {
        $merchant = $this->resolve($this->argument('merchant'));

        if ($merchant === null) {
            $this->error('No merchant matches that id, name or email.');

            return self::FAILURE;
        }

        $dir = database_path('seeders/storefront');
        $manifest = $dir.'/manifest.json';

        if (! is_file($manifest)) {
            $this->error("No manifest at {$manifest}.");

            return self::FAILURE;
        }

        if ($this->option('replace')) {
            foreach ($merchant->photos()->get() as $existing) {
                $images->purge($existing->image_path);
                $existing->delete();
            }
        }

        /*
         * Never on top of photos a merchant took themselves. Samples pushed in
         * beside real ones is the worst of both — the carousel looks unfinished
         * and the merchant did the work for nothing.
         */
        if ($merchant->photos()->exists()) {
            $this->line('');
            $this->line('  <fg=yellow>This restaurant already has photos.</> Use --replace to swap them.');
            $this->line('');

            return self::SUCCESS;
        }

        $limit = (int) config('media.max_storefront_photos');
        $slides = array_slice(json_decode(file_get_contents($manifest), true), 0, $limit);

        foreach ($slides as $slide) {
            $file = $dir.'/'.$slide['slug'].'.png';

            if (! is_file($file)) {
                continue;
            }

            $stored = $images->store(
                'storefront/'.$merchant->id,
                // Copied, not moved: the seed set has to survive the next
                // restaurant.
                new UploadedFile($file, $slide['slug'].'.png', 'image/png', null, true),
            );

            $photo = $merchant->photos()->make([
                'caption' => $slide['caption'],
                'position' => $slide['position'],
            ]);

            // forceFill, because image_path is deliberately not mass assignable.
            $photo->forceFill(['image_path' => $stored])->save();
        }

        $this->line('');
        $this->line('  <fg=green;options=bold>'.count($slides)."</> sample photos added to <options=bold>{$merchant->business_name}</>.");
        $this->line('');
        $this->line('  <fg=gray>Every slide says "sample image". Ask the merchant to photograph the</>');
        $this->line('  <fg=gray>real room and counter — a phone near a window beats any of these,</>');
        $this->line('  <fg=gray>and it is the one thing that sells a place nobody has visited.</>');
        $this->line('');

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
