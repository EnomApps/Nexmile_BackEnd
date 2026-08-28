<?php

namespace App\Services\Discovery;

/**
 * The filter and sort options a customer can apply to the restaurant list.
 *
 * A named object rather than a widening list of positional arguments: the
 * nearby search already took four, and the home screen adds ten more. It also
 * gives one place to answer "what did we actually apply", which the filter
 * sheet needs to render "Show results (42)".
 */
class RestaurantFilters
{
    public const SORTS = ['relevance', 'rating', 'delivery_time', 'cost_low_high', 'cost_high_low'];

    /**
     * @param  list<string>  $cuisines
     */
    public function __construct(
        public readonly ?string $serviceCategory = null,
        public readonly ?string $search = null,
        public readonly string $sort = 'relevance',
        public readonly array $cuisines = [],
        public readonly ?float $ratingMin = null,
        public readonly ?int $costMin = null,
        public readonly ?int $costMax = null,
        public readonly bool $vegOnly = false,
        public readonly bool $freeDelivery = false,
        public readonly bool $noPackagingFee = false,
        public readonly bool $nearAndFast = false,
        public readonly bool $hasOffers = false,
        public readonly bool $openNow = false,
        public readonly ?int $perPage = null,
    ) {}

    /**
     * Build from validated request input.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $cost = self::costBracket($data['cost_for_two'] ?? null);

        return new self(
            serviceCategory: $data['service_category'] ?? null,
            search: $data['search'] ?? null,
            sort: $data['sort'] ?? 'relevance',
            cuisines: array_values(array_filter((array) ($data['cuisine'] ?? []))),
            ratingMin: isset($data['rating_min']) ? (float) $data['rating_min'] : null,
            costMin: $data['cost_min'] ?? $cost[0] ?? null,
            costMax: $data['cost_max'] ?? $cost[1] ?? null,
            vegOnly: (bool) ($data['veg_only'] ?? false),
            freeDelivery: (bool) ($data['free_delivery'] ?? false),
            noPackagingFee: (bool) ($data['no_packaging_fee'] ?? false),
            nearAndFast: (bool) ($data['near_and_fast'] ?? false),
            hasOffers: (bool) ($data['has_offers'] ?? false),
            openNow: (bool) ($data['open_now'] ?? false),
            perPage: isset($data['per_page']) ? (int) $data['per_page'] : null,
        );
    }

    /**
     * "0-150", "150-300", "300-" from the filter sheet's range_choice options.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function costBracket(?string $bracket): array
    {
        if ($bracket === null || ! str_contains($bracket, '-')) {
            return [null, null];
        }

        [$from, $to] = array_pad(explode('-', $bracket, 2), 2, '');

        return [$from === '' ? null : (int) $from, $to === '' ? null : (int) $to];
    }

    /**
     * What was actually applied, for the `meta.applied_filters` the filter
     * sheet echoes back. Empty values are omitted rather than sent as null —
     * the app treats a present key as an active chip.
     *
     * @return array<string, mixed>
     */
    public function applied(): array
    {
        return array_filter([
            'service_category' => $this->serviceCategory,
            'search' => $this->search,
            'sort' => $this->sort === 'relevance' ? null : $this->sort,
            'cuisine' => $this->cuisines ?: null,
            'rating_min' => $this->ratingMin,
            'cost_min' => $this->costMin,
            'cost_max' => $this->costMax,
            'veg_only' => $this->vegOnly ?: null,
            'free_delivery' => $this->freeDelivery ?: null,
            'no_packaging_fee' => $this->noPackagingFee ?: null,
            'near_and_fast' => $this->nearAndFast ?: null,
            'has_offers' => $this->hasOffers ?: null,
            'open_now' => $this->openNow ?: null,
        ], fn ($value) => $value !== null);
    }
}
