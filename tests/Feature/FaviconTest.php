<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The icon browsers actually draw.
 *
 * Android was showing a grey circle with an N in it for the saved shortcut,
 * because the only icon on offer was the 430x256 wordmark. An icon has to be
 * square; given one that is not, a browser either squashes it or gives up and
 * draws a letter tile from the site name.
 *
 * The other half was `public/favicon.ico` sitting there at zero bytes — the
 * placeholder the framework ships. Browsers request that path whether or not
 * anything links to it, and an empty file is a a confident-looking nothing.
 */
class FaviconTest extends TestCase
{
    /** @return list<string> */
    public static function iconFiles(): array
    {
        return [
            ['public/favicon.ico'],
            ['public/images/icon-32.png'],
            ['public/images/icon-192.png'],
            ['public/images/icon-512.png'],
            ['public/images/apple-touch-icon.png'],
            ['public/site.webmanifest'],
        ];
    }

    #[DataProvider('iconFiles')]
    public function test_every_icon_file_exists_and_is_not_empty(string $path): void
    {
        $full = base_path($path);

        $this->assertFileExists($full);

        // The failure that started this: a zero-byte favicon.ico is present,
        // served, and useless.
        $this->assertGreaterThan(
            100,
            filesize($full),
            "{$path} exists but is empty or near enough.",
        );
    }

    public function test_the_png_icons_are_square(): void
    {
        /*
         * The whole cause. Chrome will not use a rectangular image as a
         * shortcut icon, and nothing reports why — it just draws a letter.
         */
        foreach (['icon-32', 'icon-192', 'icon-512', 'apple-touch-icon'] as $name) {
            [$width, $height] = getimagesize(base_path("public/images/{$name}.png"));

            $this->assertSame($width, $height, "{$name}.png is {$width}x{$height}, not square.");
        }
    }

    public function test_the_icons_are_the_sizes_they_claim(): void
    {
        // A file called icon-192 that is not 192 pixels is worse than no file,
        // because the link tag tells the browser to trust the name.
        foreach (['icon-32' => 32, 'icon-192' => 192, 'icon-512' => 512, 'apple-touch-icon' => 180] as $name => $expected) {
            [$width] = getimagesize(base_path("public/images/{$name}.png"));

            $this->assertSame($expected, $width, "{$name}.png is {$width}px wide.");
        }
    }

    public function test_the_manifest_is_valid_and_points_at_real_files(): void
    {
        $manifest = json_decode(file_get_contents(base_path('public/site.webmanifest')), true);

        $this->assertIsArray($manifest, 'The manifest is not valid JSON.');
        $this->assertNotEmpty($manifest['icons'] ?? []);

        // A manifest naming an icon that is not there is how a home-screen
        // shortcut silently falls back to a letter again.
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(base_path('public'.$icon['src']));
        }
    }

    public function test_the_public_site_offers_the_whole_set(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['favicon.ico', 'icon-192.png', 'apple-touch-icon.png', 'site.webmanifest'] as $asset) {
            $this->assertStringContainsString($asset, $html);
        }
    }

    public function test_the_rectangular_wordmark_is_no_longer_used_as_an_icon(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // It is still the logo in the page. It must not be in a rel="icon".
        $this->assertStringNotContainsString(
            'rel="icon" href="'.asset('images/nexmile-mark.png').'"',
            $html,
        );
    }
}
