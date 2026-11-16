<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\RiderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RefreshToken;
use App\Models\Rider;
use App\Models\RiderPayout;
use App\Models\Zone;
use App\Services\LiveState\RiderLocationService;
use App\Services\Riders\ConductService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Everything known about one rider, on one page.
 *
 * All of this existed before — scattered. Identity and documents were on the
 * KYC review screen, complaints on the conduct screen, earnings only inside
 * the rider's own app, duty status only in a database column, and position
 * only in Redis where no human could read it. Answering "what is going on
 * with this rider" meant four screens and a database client, so in practice
 * nobody answered it until a rider or a customer forced the question.
 *
 * Nothing here is a new source of truth. Every figure is read from whatever
 * already owns it, which is why this controller is mostly queries and has
 * almost no arithmetic: a performance number computed here that disagreed
 * with the rider's own app would be worse than showing none.
 */
class RiderController extends Controller
{
    /**
     * The most points either trail view will draw.
     *
     * A map the browser cannot render is no more honest than a truncated
     * one, so the view is told when it has hit this rather than quietly
     * showing part of a day as though it were all of it.
     */
    private const TRAIL_LIMIT = 2000;

    /** Columns an admin may sort by, mapped to how it is actually done. */
    private const SORTS = [
        'name' => 'full_name',
        'joined' => 'created_at',
        'deliveries' => 'completed_deliveries',
        'rating' => 'rating',
        'seen' => 'last_location_at',
        'acceptance' => 'acceptance',
    ];

    public function __construct(
        protected RiderLocationService $locations,
        protected ConductService $conduct,
    ) {}

    /**
     * The roster: who is on, who is near, who needs looking at.
     */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'duty' => ['sometimes', 'nullable', 'in:'.$this->values(RiderStatus::cases())],
            'kyc' => ['sometimes', 'nullable', 'in:'.$this->values(KycStatus::cases())],
            'zone' => ['sometimes', 'nullable', 'integer'],
            'sort' => ['sometimes', 'in:'.implode(',', array_keys(self::SORTS))],
            'dir' => ['sometimes', 'in:asc,desc'],
        ]);

        $sort = $filters['sort'] ?? 'seen';

        // Whitelisted by the rules above, which is what makes it safe to
        // interpolate below — and it has to be interpolated, because an
        // order direction cannot be a bound parameter.
        $dir = $filters['dir'] ?? 'desc';

        $term = trim((string) ($filters['q'] ?? ''));
        $duty = $filters['duty'] ?? null;
        $kyc = $filters['kyc'] ?? null;
        $zone = $filters['zone'] ?? null;

        $query = Rider::query()
            ->with(['user:id,name,phone,email,status', 'zone:id,name'])
            ->withCount(['warnings as counting_warnings' => fn ($q) => $q->counting()]);

        if ($term !== '') {
            /*
             * One box, several columns. An admin looking for a rider has
             * whichever detail the person on the phone gave them — a name, a
             * number plate, the number they are calling from — and asking
             * which kind of thing it is first is how a search box goes unused.
             */
            $query->where(function ($outer) use ($term) {
                $like = '%'.$term.'%';

                $outer->where('full_name', 'like', $like)
                    ->orWhere('vehicle_number', 'like', $like)
                    ->orWhereHas('user', fn ($q) => $q
                        ->where('phone', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('name', 'like', $like));

                // The id every other screen shows them.
                if (ctype_digit($term)) {
                    $outer->orWhere('id', (int) $term);
                }
            });
        }

        if ($duty !== null) {
            $query->where('duty_status', $duty);
        }

        if ($kyc !== null) {
            $query->where('kyc_status', $kyc);
        }

        if ($zone !== null) {
            $query->where('zone_id', $zone);
        }

        if ($sort === 'acceptance') {
            /*
             * A rider who has never been offered anything sorts below 0%
             * rather than at it: they have refused nothing, and a worst-first
             * list that opens with the newest rider on the roster sends an
             * admin after the wrong person.
             */
            $query->orderByRaw(
                "CASE WHEN offers_received > 0 THEN (offers_accepted * 1.0 / offers_received) ELSE -1 END {$dir}"
            );
        } else {
            $query->orderBy(self::SORTS[$sort], $dir);
        }

        $riders = $query->paginate(25)->withQueryString();

        return view('admin.riders.index', [
            'riders' => $riders,
            'presence' => $this->presenceFor($riders),
            'standings' => $riders->getCollection()
                ->mapWithKeys(fn (Rider $r) => [$r->id => $this->conduct->standing($r)]),
            'counts' => $this->rosterCounts(),
            'zones' => Zone::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'q' => $term,
                'duty' => $duty,
                'kyc' => $kyc,
                'zone' => $zone,
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    /**
     * One rider, whole.
     */
    public function show(Request $request, Rider $rider): View
    {
        $data = $request->validate([
            'scope' => ['sometimes', 'in:order,day'],
            'order' => ['sometimes', 'nullable', 'integer'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $rider->load(['user:id,name,phone,email,status,preferred_locale,created_at', 'zone:id,name']);
        $rider->loadCount(['warnings as counting_warnings' => fn ($q) => $q->counting()]);

        /*
         * The trail is shown one delivery at a time unless an admin
         * deliberately asks for a whole day.
         *
         * A day includes the hours between orders — a rider on duty and
         * idle, which is where they eat, where they wait and plausibly
         * where they live. None of that is needed to answer the question
         * this trail exists for, which is always about a specific order:
         * did the rider reach this address, and when. Scoping to the order
         * answers that completely and exposes almost nothing else.
         *
         * The wide view is kept rather than removed. "Where was this rider
         * all afternoon" is a legitimate question when somebody is being
         * paid for a shift that produced nothing — it is just not the
         * default, so looking at an idle rider's whole day is a decision
         * somebody made rather than the screen they happened to land on.
         */
        $trailOrders = $this->trailOrders($rider);
        $scope = $data['scope'] ?? 'order';

        $date = isset($data['date'])
            ? Carbon::parse($data['date'])->startOfDay()
            : Carbon::now()->startOfDay();

        if ($scope === 'day') {
            $trail = $this->trailForDay($rider, $date);
            $trailOrderId = null;
        } else {
            /*
             * Defaults to the most recent delivery with anything recorded,
             * because that is overwhelmingly the one being asked about.
             */
            $trailOrderId = isset($data['order'])
                ? (int) $data['order']
                : $trailOrders->first()?->order_id;

            $trail = $this->trailForOrder($rider, $trailOrderId);
        }

        return view('admin.riders.show', [
            'rider' => $rider,
            'presence' => $this->presence($rider),
            'standing' => $this->conduct->standing($rider),

            /*
             * The same documents and the same actions as the verification
             * queue. This page took over that URL for riders, so it has to
             * carry the whole job and not just the readable half of it.
             */
            'documents' => $rider->kycDocuments()->latest('id')->get(),
            'required' => config('kyc.required.rider'),

            'counts' => $this->orderCounts($rider),
            'orders' => $this->orders($rider),
            'performance' => $this->performance($rider),
            'earnings' => $this->earnings($rider),
            'sessions' => $this->sessions($rider),
            'trail' => $trail,
            'trailScope' => $scope,
            'trailOrders' => $trailOrders,
            'trailOrderId' => $trailOrderId,
            'trailDays' => $this->trailDays($rider),
            'trailDate' => $date,
            'retentionDays' => (int) config('tracking.history.retention_days'),
            'historyEnabled' => (bool) config('tracking.history.enabled'),
            'mapKey' => config('services.google_maps.browser_key'),
        ]);
    }

    /**
     * Where the rider is, as JSON, for the page to poll.
     *
     * Its own endpoint rather than reloading the page: an admin watching a
     * rider move would otherwise re-run every query on this screen every few
     * seconds, earnings aggregates included.
     */
    public function position(Rider $rider): JsonResponse
    {
        return response()->json(['data' => $this->presence($rider)]);
    }

    /**
     * Presence and position for one rider, Redis first.
     *
     * Falls back to the columns on the row, which hold the last fix MySQL
     * saw. Says which of the two it used: an admin looking at a dot on a map
     * needs to know whether it is live or the last thing recorded before
     * Redis restarted, because those mean very different things about where
     * the rider actually is.
     *
     * @return array<string, mixed>
     */
    protected function presence(Rider $rider): array
    {
        $state = rescue(fn () => $this->locations->state($rider->id), [], report: false);
        $present = rescue(fn () => $this->locations->isPresent($rider->id), false, report: false);

        $latitude = isset($state['latitude']) && $state['latitude'] !== ''
            ? (float) $state['latitude']
            : ($rider->last_latitude !== null ? (float) $rider->last_latitude : null);

        $longitude = isset($state['longitude']) && $state['longitude'] !== ''
            ? (float) $state['longitude']
            : ($rider->last_longitude !== null ? (float) $rider->last_longitude : null);

        $recordedAt = isset($state['recorded_at']) && $state['recorded_at'] !== ''
            ? rescue(fn () => Carbon::parse($state['recorded_at']), null, report: false)
            : $rider->last_location_at;

        $staleAfter = (int) config('tracking.stale_after_seconds');

        return [
            'present' => $present,
            'duty_status' => $rider->duty_status->value,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'heading' => isset($state['heading']) && $state['heading'] !== '' ? (float) $state['heading'] : null,
            'speed' => isset($state['speed']) && $state['speed'] !== '' ? (float) $state['speed'] : null,
            'recorded_at' => $recordedAt,
            'seconds_ago' => $recordedAt === null ? null : $recordedAt->diffInSeconds(now()),
            'stale' => $recordedAt === null || $recordedAt->lt(now()->subSeconds($staleAfter)),
            'source' => $state !== []
                ? 'live'
                : ($rider->last_location_at !== null ? 'last recorded' : 'nothing recorded'),
            'stale_after_seconds' => $staleAfter,
        ];
    }

    /**
     * Presence for a page of riders.
     *
     * One Redis round trip each — twenty-five on a full page, and cheap. But
     * wrapped, so that Redis being down greys the column out rather than
     * returning a 500 for the whole roster.
     *
     * @return Collection<int, bool>
     */
    protected function presenceFor(LengthAwarePaginator $riders): Collection
    {
        return $riders->getCollection()->mapWithKeys(fn (Rider $rider) => [
            $rider->id => rescue(fn () => $this->locations->isPresent($rider->id), false, report: false),
        ]);
    }

    /** How the roster splits by duty status, for the filter chips. */
    protected function rosterCounts(): Collection
    {
        $counts = Rider::query()
            ->selectRaw('duty_status, COUNT(*) as n')
            ->groupBy('duty_status')
            ->pluck('n', 'duty_status');

        return collect(RiderStatus::cases())
            ->mapWithKeys(fn (RiderStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->put('all', (int) $counts->sum());
    }

    /**
     * Orders by status, in one query.
     *
     * Grouped rather than a count per status. This page runs enough queries
     * already, and a dozen COUNT(*)s differing only in a WHERE clause is the
     * sort of thing that makes an admin screen slow for a reason nobody can
     * point at six months later.
     *
     * @return array<string, int>
     */
    protected function orderCounts(Rider $rider): array
    {
        $byStatus = $rider->orders()
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $finished = Order::finishedStatuses();

        return [
            'total' => (int) $byStatus->sum(),
            'delivered' => (int) ($byStatus[OrderStatus::Delivered->value] ?? 0),
            'cancelled' => (int) ($byStatus[OrderStatus::Cancelled->value] ?? 0),
            'rejected' => (int) ($byStatus[OrderStatus::Rejected->value] ?? 0),
            // Everything still in hand, however far along it is.
            'ongoing' => (int) $byStatus
                ->reject(fn ($n, $status) => in_array($status, $finished, true))
                ->sum(),
        ];
    }

    /** The rider's order list, newest first. */
    protected function orders(Rider $rider): LengthAwarePaginator
    {
        return $rider->orders()
            ->with(['merchant:id,business_name', 'customer:id,name'])
            ->latest('id')
            /*
             * Its own page name. Paging the orders must not also reset
             * whichever day of the trail the admin was reading.
             */
            ->paginate(15, ['*'], 'orders')
            ->withQueryString();
    }

    /**
     * How well the rider actually works.
     *
     * The averages are computed in PHP over recent deliveries rather than in
     * SQL, for two reasons. `TIMESTAMPDIFF` is MySQL's and the test suite
     * runs on SQLite, so driver-specific date arithmetic here would leave
     * this page exercised only by hand. And recent is the more useful
     * question anyway — a lifetime average hides a rider who has got slower.
     *
     * @return array<string, mixed>
     */
    protected function performance(Rider $rider): array
    {
        $recent = $rider->orders()
            ->where('status', OrderStatus::Delivered->value)
            ->whereNotNull('delivered_at')
            ->latest('delivered_at')
            ->limit(50)
            ->get(['assigned_at', 'arrived_at', 'picked_up_at', 'delivered_at']);

        return [
            'completed_deliveries' => (int) $rider->completed_deliveries,
            'rating' => $rider->rating === null ? null : (float) $rider->rating,
            'rating_count' => (int) $rider->rating_count,
            'offers_received' => (int) $rider->offers_received,
            'offers_accepted' => (int) $rider->offers_accepted,
            'acceptance_rate' => $rider->acceptanceRate(),

            /*
             * Offers let ring out in a row. Dispatch takes a rider off duty
             * at the configured limit, so a number approaching it explains a
             * rider who keeps finding themselves offline and reports the app
             * as broken.
             */
            'consecutive_misses' => (int) $rider->consecutive_misses,
            'auto_offline_after' => (int) config('dispatch.auto_offline_after_misses'),

            'sample' => $recent->count(),
            'minutes_riding' => $this->averageMinutes($recent, 'picked_up_at', 'delivered_at'),
            'minutes_total' => $this->averageMinutes($recent, 'assigned_at', 'delivered_at'),
            'minutes_waiting' => $this->averageMinutes($recent, 'arrived_at', 'picked_up_at'),
        ];
    }

    /**
     * Mean minutes between two timestamps, across the rows that have both.
     *
     * Null when nothing qualifies, which the view draws as a dash. A zero
     * would read as "instant" rather than "never measured", and those two get
     * acted on very differently.
     *
     * @param  Collection<int, Order>  $orders
     */
    protected function averageMinutes(Collection $orders, string $from, string $to): ?float
    {
        $spans = $orders
            ->filter(fn (Order $order) => $order->{$from} !== null && $order->{$to} !== null)
            ->map(fn (Order $order) => $order->{$from}->diffInSeconds($order->{$to}))
            /*
             * A negative span is a clock or a backfill, not a delivery that
             * finished before it started. Dropped rather than averaged in,
             * where it would quietly pull every figure down.
             */
            ->filter(fn (int $seconds) => $seconds >= 0);

        return $spans->isEmpty() ? null : round($spans->avg() / 60, 1);
    }

    /**
     * What the rider has earned, and what has actually been sent.
     *
     * Deliberately two different numbers. Earned comes from the orders, sent
     * comes from the weekly statements, and the gap between them is work not
     * yet on any statement — which is what a rider asking "where is my money"
     * is really asking about.
     *
     * @return array<string, mixed>
     */
    protected function earnings(Rider $rider): array
    {
        $delivered = (float) $rider->orders()
            ->where('status', OrderStatus::Delivered->value)
            ->sum('rider_payout');

        /*
         * The last week any statement covers. Work after it is earned and on
         * no statement yet, which is the honest way to describe it — calling
         * that "pending" implies a transfer exists somewhere, and none does.
         */
        $lastPeriodEnd = $rider->payouts()->max('period_end');

        $sinceStatement = $lastPeriodEnd === null
            ? $delivered
            : (float) $rider->orders()
                ->where('status', OrderStatus::Delivered->value)
                ->where('delivered_at', '>', Carbon::parse($lastPeriodEnd)->endOfDay())
                ->sum('rider_payout');

        return [
            'payouts' => $rider->payouts()->latest('period_start')->limit(12)->get(),
            'delivered_total' => round($delivered, 2),
            'paid' => round((float) $rider->payouts()->paid()->sum('net'), 2),
            'awaiting' => round((float) $rider->payouts()->payable()->sum('net'), 2),
            'carried' => round((float) $rider->payouts()->where('status', RiderPayout::CARRIED)->sum('net'), 2),
            'since_statement' => round($sinceStatement, 2),
            'last_period_end' => $lastPeriodEnd === null ? null : Carbon::parse($lastPeriodEnd),

            /*
             * Shown because it is the single most common reason a payout run
             * skips somebody, and it is invisible everywhere else in the
             * portal.
             */
            'bank_on_file' => $rider->bank_ifsc !== null && $rider->bank_account_name !== null,
        ];
    }

    /**
     * Sign-ins, sign-outs and last activity.
     *
     * Read from the refresh tokens, which have been recording all of this
     * since authentication was built — nothing was reading them. A row is
     * created at sign-in, stamped each time the app exchanges it, and marked
     * revoked at sign-out rather than deleted, so the history survives.
     *
     * The one subtlety: refreshing rotates the token, so most rows are not
     * sign-ins. A row is a real sign-in only when no other row was replaced
     * by it — otherwise an app quietly renewing every hour would read as a
     * rider signing in twenty times a day.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function sessions(Rider $rider): Collection
    {
        $tokens = RefreshToken::query()
            ->where('user_id', $rider->user_id)
            ->latest('id')
            ->limit(40)
            ->get([
                'id', 'device_name', 'ip_address', 'expires_at',
                'last_used_at', 'revoked_at', 'replaced_by_id', 'created_at',
            ]);

        $rotatedInto = $tokens->pluck('replaced_by_id')->filter()->all();

        return $tokens->map(fn (RefreshToken $token) => [
            'id' => $token->id,
            'device_name' => $token->device_name,
            'ip_address' => $token->ip_address,
            'signed_in_at' => $token->created_at,
            'last_used_at' => $token->last_used_at,
            'signed_out_at' => $token->revoked_at,
            'active' => $token->isActive(),
            // A fresh sign-in, as opposed to the app renewing what it had.
            'is_sign_in' => ! in_array($token->id, $rotatedInto, true),
        ]);
    }

    /**
     * The deliveries there is a trail for, newest first.
     *
     * The picker for the default view. Built from the pings rather than from
     * the orders, because an order with nothing recorded against it has no
     * trail to offer and listing it only invites a click that shows nothing.
     *
     * @return Collection<int, object>
     */
    protected function trailOrders(Rider $rider): Collection
    {
        $rows = $rider->locationPings()
            ->whereNotNull('order_id')
            ->selectRaw('order_id, COUNT(*) as points, MIN(recorded_at) as started_at, MAX(recorded_at) as ended_at')
            ->groupBy('order_id')
            ->orderByDesc('started_at')
            ->limit(50)
            ->get();

        $orders = Order::whereIn('id', $rows->pluck('order_id'))
            ->get(['id', 'order_number', 'status', 'delivered_at'])
            ->keyBy('id');

        return $rows->map(function ($row) use ($orders) {
            $row->order = $orders->get($row->order_id);
            $row->started_at = Carbon::parse($row->started_at);
            $row->ended_at = Carbon::parse($row->ended_at);

            return $row;
        });
    }

    /**
     * The trail for one delivery — the default, and the narrow view.
     *
     * Scoped through the rider's own pings rather than queried by order id
     * alone, so a guessed or pasted id belonging to somebody else returns
     * nothing instead of another rider's movements.
     *
     * @return array<string, mixed>
     */
    protected function trailForOrder(Rider $rider, ?int $orderId): array
    {
        if ($orderId === null) {
            return $this->noTrail('order', null);
        }

        $points = $rider->locationPings()
            ->where('order_id', $orderId)
            ->orderBy('recorded_at')
            ->limit(self::TRAIL_LIMIT + 1)
            ->get(['order_id', 'latitude', 'longitude', 'heading', 'speed', 'accuracy_metres', 'recorded_at']);

        return [
            'scope' => 'order',
            'order_id' => $orderId,
            'points' => $points->take(self::TRAIL_LIMIT),
            'truncated' => $points->count() > self::TRAIL_LIMIT,
            'limit' => self::TRAIL_LIMIT,
        ];
    }

    /**
     * One whole day — the wide view, only reached deliberately.
     *
     * Capped. A full shift at twenty metres a point is a few thousand rows
     * for a rider who covered real ground, and a map the browser cannot draw
     * is no more honest than a truncated one — so the view is told when it is
     * looking at a cut-off rather than a whole day.
     *
     * @return array<string, mixed>
     */
    protected function trailForDay(Rider $rider, Carbon $date): array
    {
        $points = $rider->locationPings()
            ->whereBetween('recorded_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->orderBy('recorded_at')
            ->limit(self::TRAIL_LIMIT + 1)
            ->get(['order_id', 'latitude', 'longitude', 'heading', 'speed', 'accuracy_metres', 'recorded_at']);

        return [
            'scope' => 'day',
            'order_id' => null,
            'points' => $points->take(self::TRAIL_LIMIT),
            'truncated' => $points->count() > self::TRAIL_LIMIT,
            'limit' => self::TRAIL_LIMIT,
        ];
    }

    /** @return array<string, mixed> */
    protected function noTrail(string $scope, ?int $orderId): array
    {
        return [
            'scope' => $scope,
            'order_id' => $orderId,
            'points' => collect(),
            'truncated' => false,
            'limit' => self::TRAIL_LIMIT,
        ];
    }

    /**
     * The days there is a trail for, newest first.
     *
     * So the picker offers days with something on them, rather than a
     * calendar an admin has to guess at.
     *
     * @return Collection<int, string>
     */
    protected function trailDays(Rider $rider): Collection
    {
        return $rider->locationPings()
            ->selectRaw('DATE(recorded_at) as day')
            ->groupBy('day')
            ->orderByDesc('day')
            ->limit(60)
            ->pluck('day')
            ->map(fn ($day) => (string) $day);
    }

    /** @param  array<int, KycStatus|RiderStatus>  $cases */
    private function values(array $cases): string
    {
        return implode(',', array_map(fn ($case) => $case->value, $cases));
    }
}
