<?php

namespace App\Services\Discovery;

use App\Contracts\RoadDistanceProvider;
use App\Models\Merchant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Road distance for the restaurants actually being shown.
 *
 * Never for every candidate. A 1 km search can cross a hundred restaurants and
 * the provider bills per pair, so measuring them all would cost a hundred
 * times what it is worth to correct a list of fifteen.
 *
 * Two things make it affordable. Only the page is measured, the way matched
 * dishes already work. And the answers are cached hard: a road network does
 * not change between lunch and dinner, and the same customer orders from the
 * same address to the same restaurants for months.
 */
class RoadDistanceService
{
    public function __construct(protected RoadDistanceProvider $provider) {}

    /**
     * Attach `road_distance_metres` to each merchant, where it can be known.
     *
     * Left null when the provider cannot answer. Callers keep using the
     * straight line, which is what the product did before this existed.
     *
     * @param  Collection<int, Merchant>  $merchants
     */
    public function attach(Collection $merchants, float $latitude, float $longitude): void
    {
        if (! config('discovery.road_distance.enabled') || $merchants->isEmpty()) {
            return;
        }

        $cached = [];
        $missing = [];

        foreach ($merchants as $merchant) {
            if ($merchant->latitude === null || $merchant->longitude === null) {
                continue;
            }

            $key = $this->key($latitude, $longitude, $merchant);
            $hit = Cache::get($key);

            if ($hit !== null) {
                // Stored as -1 rather than null, because a cache miss and a
                // cached "we asked and nobody knows" are different facts and
                // null cannot tell them apart.
                $cached[$merchant->id] = $hit === -1 ? null : (int) $hit;

                continue;
            }

            $missing[$merchant->id] = $merchant;
        }

        $measured = $this->measure($missing, $latitude, $longitude);

        foreach ($merchants as $merchant) {
            $merchant->road_distance_metres = array_key_exists($merchant->id, $cached)
                ? $cached[$merchant->id]
                : ($measured[$merchant->id] ?? null);
        }
    }

    /**
     * Ask the provider about whatever was not cached, in one request.
     *
     * @param  array<int, Merchant>  $missing
     * @return array<int, int|null>
     */
    protected function measure(array $missing, float $latitude, float $longitude): array
    {
        if ($missing === []) {
            return [];
        }

        $ids = array_keys($missing);
        $results = [];
        $perCall = (int) config('discovery.road_distance.max_destinations_per_call', 25);

        foreach (array_chunk($ids, $perCall) as $chunk) {
            $destinations = array_map(fn (int $id) => [
                'lat' => (float) $missing[$id]->latitude,
                'lng' => (float) $missing[$id]->longitude,
            ], $chunk);

            $metres = $this->provider->measure($latitude, $longitude, $destinations);

            foreach ($chunk as $i => $id) {
                $value = $metres[$i] ?? null;
                $results[$id] = $value;

                Cache::put(
                    $this->key($latitude, $longitude, $missing[$id]),
                    $value ?? -1,
                    /*
                     * A failure is remembered briefly, a real answer for a
                     * long time. Otherwise one bad afternoon of API errors is
                     * cached over a road network that has not changed since
                     * the bridge was built.
                     */
                    $value === null
                        ? (int) config('discovery.road_distance.failure_ttl_seconds', 300)
                        : (int) config('discovery.road_distance.ttl_seconds', 2592000),
                );
            }
        }

        return $results;
    }

    /**
     * Cache key for one customer point and one restaurant.
     *
     * The customer's position is rounded onto a grid before it becomes a key.
     * Exact coordinates would mean a fresh lookup for every few metres a phone
     * drifts, and a cache that never hits is a cache that only costs money.
     *
     * The grid is a deliberate inaccuracy: at three decimal places it is about
     * a hundred metres, so two customers on the same street share an answer.
     * Over a journey of a kilometre or two that is well inside the error of
     * the routing itself.
     */
    protected function key(float $latitude, float $longitude, Merchant $merchant): string
    {
        $precision = (int) config('discovery.road_distance.coordinate_precision', 3);

        return 'road:'
            .number_format($latitude, $precision, '.', '').':'
            .number_format($longitude, $precision, '.', '').':'
            .$merchant->id;
    }
}
