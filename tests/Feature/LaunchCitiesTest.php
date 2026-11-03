<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The launch cities are a public commitment, made in three languages.
 *
 * Nexmile opens in Coimbatore, Madurai and Ooty at the same time. A visitor
 * in one of them who does not see their own city on the home page concludes
 * we are not coming, and a language file that still lists only Madurai makes
 * that true for every Tamil or Hindi reader.
 */
class LaunchCitiesTest extends TestCase
{
    /** In the order the founder asked for them. */
    private const CITIES = ['Coimbatore', 'Madurai', 'Ooty'];

    public function test_all_three_cities_are_named_on_the_home_page(): void
    {
        $this->get('/')->assertOk()->assertSeeInOrder(self::CITIES);
    }

    public function test_every_language_lists_the_same_number_of_cities(): void
    {
        /*
         * The names are translated, so they cannot be compared across
         * locales — but the count can, and a locale left behind shows one
         * pill where the others show three.
         */
        foreach (array_keys(config('site.locales')) as $locale) {
            $this->assertCount(
                count(self::CITIES),
                (array) __('site.cities', [], $locale),
                "{$locale} does not list all the launch cities"
            );
        }
    }

    public function test_each_language_renders_its_own_city_names(): void
    {
        // Transliterations, checked against the Tamil Nadu government's own
        // spellings rather than invented here.
        $expected = [
            'ta' => ['கோயம்புத்தூர்', 'மதுரை', 'ஊட்டி'],
            'hi' => ['कोयंबटूर', 'मदुरै', 'ऊटी'],
        ];

        foreach ($expected as $locale => $cities) {
            $this->withSession(['locale' => $locale])
                ->get('/')
                ->assertOk()
                ->assertSeeInOrder($cities, false);
        }
    }

    public function test_the_launch_figure_counts_the_published_cities(): void
    {
        /*
         * The hero stat used to read "Tamil Nadu / Launching first", which
         * said one city was ahead of the others. It is now the size of the
         * list below it, so the two can never disagree — a fourth city
         * announced in one place is announced in both.
         */
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(
            '>'.count(self::CITIES).'</div>',
            $html,
            'the launch stat does not show the number of cities'
        );

        $this->assertStringNotContainsString('Launching first', $html);
    }
}
