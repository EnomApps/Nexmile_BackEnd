<?php

namespace App\Services\Discovery;

use App\Enums\KycStatus;
use App\Models\Merchant;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Finding the restaurants a customer can actually order from (EP4).
 *
 * This runs in SQL + PHP, not Redis GEO — unlike riders.
 *
 * A rider's position changes every few seconds, so it belongs in a structure
 * built for churn. A merchant's address changes roughly never. Mirroring
 * static rows into Redis would buy nothing and add a synchronisation problem:
 * every address edit, every new merchant, every restore from backup becomes a
 * chance for the index to disagree with the table.
 *
 * The split is deliberate. A **bounding box in SQL** uses the existing
 * (latitude, longitude) index and cuts the table down to a handful of
 * candidates; the **exact haversine runs in PHP** over those.
 *
 * Doing the trigonometry in SQL would be fewer lines, but MySQL, SQLite and
 * Postgres disagree about which maths functions exist — SQLite ships without
 * any unless compiled for them. Writing it in SQL means the test suite either
 * exercises a different query than production or cannot run at all, and a test
 * that does not run the real path is the kind that hides a bug until a user
 * finds it.
 */
class NearbyMerchantService
{
    /** Mean radius of the Earth, in metres. */
    private const EARTH_RADIUS = 6371000;

    /**
     * Restaurants within range of a point, nearest first, open ones above
     * closed ones.
     */
    public function search(
        float $latitude,
        float $longitude,
        ?string $serviceCategory = null,
        ?string $search = null,
        ?int $perPage = null,
    ): LengthAwarePaginator {
        $radius = $this->radiusFor($latitude, $longitude);
        $perPage ??= (int) config('discovery.per_page');

        $candidates = $this->candidates($latitude, $longitude, $radius, $serviceCategory, $search);

        $matches = $candidates
            ->each(fn (Merchant $m) => $m->distance_metres = $this->distance(
                $latitude, $longitude, (float) $m->latitude, (float) $m->longitude,
            ))
            ->filter(fn (Merchant $m) => $m->distance_metres <= $radius)
            /*
             * Open first, then nearest. A customer wants dinner now, so the
             * closest restaurant that cannot cook is worth less than one two
             * hundred metres further that can.
             */
            ->sortBy([
                fn (Merchant $a, Merchant $b) => ($b->isOpenNow() <=> $a->isOpenNow()),
                fn (Merchant $a, Merchant $b) => $a->distance_metres <=> $b->distance_metres,
            ])
            ->values();

        return $this->paginate($matches, $perPage);
    }

    /**
     * The zone's radius when the point falls in one, otherwise the default.
     * Lets ops open up a sparse area without a deploy.
     */
    public function radiusFor(float $latitude, float $longitude): int
    {
        $radius = $this->zoneFor($latitude, $longitude)?->radius_metres
            ?? config('discovery.radius_metres');

        // A misconfigured zone must not silently turn a hyperlocal service
        // into a city-wide one.
        return (int) min($radius, config('discovery.max_radius_metres'));
    }

    /**
     * The active zone whose escalation ceiling covers this point, nearest
     * centre first when they overlap.
     */
    public function zoneFor(float $latitude, float $longitude): ?Zone
    {
        return Zone::query()
            ->where('is_active', true)
            ->get()
            ->map(function (Zone $zone) use ($latitude, $longitude) {
                $zone->distance_metres = $this->distance(
                    $latitude, $longitude,
                    (float) $zone->centre_latitude, (float) $zone->centre_longitude,
                );

                return $zone;
            })
            ->filter(fn (Zone $zone) => $zone->distance_metres <= $zone->max_radius_metres)
            ->sortBy('distance_metres')
            ->first();
    }

    /**
     * Great-circle distance in metres — haversine.
     *
     * Not the spherical law of cosines, which is shorter but loses precision
     * over short distances: `cos` flattens near zero, so a few hundred metres
     * is exactly where its error is worst, and that is the only range this
     * product operates in.
     */
    public function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS * asin(min(1.0, sqrt($a)));
    }

    /**
     * The cheap prefilter: everything in a square around the point that is
     * verified and locatable.
     *
     * @return EloquentCollection<int, Merchant>
     */
    protected function candidates(
        float $latitude,
        float $longitude,
        int $radius,
        ?string $serviceCategory,
        ?string $search,
    ): EloquentCollection {
        [$latDelta, $lngDelta] = $this->box($latitude, $radius);

        return Merchant::query()
            ->with('operatingHours')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            /*
             * Unverified merchants must never appear. KYC is what establishes
             * that a real, licensed food business is behind the storefront.
             */
            ->where('kyc_status', KycStatus::Verified->value)
            ->whereBetween('latitude', [$latitude - $latDelta, $latitude + $latDelta])
            ->whereBetween('longitude', [$longitude - $lngDelta, $longitude + $lngDelta])
            ->when($serviceCategory, fn (Builder $q) => $q->where('service_category', $serviceCategory))
            ->when($search, fn (Builder $q) => $q->where('business_name', 'like', '%'.$search.'%'))
            /*
             * A ceiling, not a page size: the box is already small, and this
             * only exists so a bad radius cannot pull the whole table into
             * memory. Reaching it would mean the box is wrong.
             */
            ->limit((int) config('discovery.max_candidates'))
            ->get();
    }

    /**
     * Half-width and half-height of the search square, in degrees.
     *
     * @return array{float, float}
     */
    protected function box(float $latitude, int $radius): array
    {
        $latDelta = rad2deg($radius / self::EARTH_RADIUS);

        /*
         * Longitude degrees narrow towards the poles, so the box widens by
         * 1/cos(latitude). Guarded because that term explodes at the poles —
         * irrelevant for Tamil Nadu, but a division by zero here would return
         * nothing at all rather than failing loudly.
         */
        $cos = cos(deg2rad($latitude));
        $lngDelta = abs($cos) < 1e-6 ? 180.0 : rad2deg($radius / (self::EARTH_RADIUS * $cos));

        return [$latDelta, $lngDelta];
    }

    /**
     * @param  Collection<int, Merchant>  $matches
     * @return LengthAwarePaginator<int, Merchant>
     */
    protected function paginate(Collection $matches, int $perPage): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $matches->forPage($page, $perPage)->values(),
            $matches->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }
}
