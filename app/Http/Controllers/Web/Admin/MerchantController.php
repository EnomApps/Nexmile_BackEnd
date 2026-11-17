<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\RefreshToken;
use App\Services\Merchant\EarningsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Everything known about one restaurant, on one page.
 *
 * The rider page's counterpart, and the simpler of the two: a restaurant has
 * one address and it never moves, so there is no trail here, no retention
 * period and no second Maps key — only figures that were already being
 * recorded and never read in one place.
 *
 * Before this, "what is going on with Hotel Vasanth" meant the verification
 * queue for their paperwork, the orders list filtered by hand for their
 * orders, and nothing at all for the two numbers that actually say whether
 * they are a good restaurant to have: how often they reject, and whether
 * their prep estimate is true.
 *
 * Money is read from `EarningsService` — the same service that builds the
 * merchant's own earnings page. Recomputing it here would eventually mean an
 * admin and a restaurant owner reading different totals for the same week,
 * and the restaurant would be right.
 */
class MerchantController extends Controller
{
    /** Columns an admin may sort by, mapped to how it is actually done. */
    private const SORTS = [
        'name' => 'business_name',
        'joined' => 'created_at',
        'rating' => 'rating',
        'orders' => 'orders_total',
        'rejects' => 'rejection',
    ];

    /** How many recent orders the timing averages are taken over. */
    private const SAMPLE = 50;

    public function __construct(protected EarningsService $earnings) {}

    /**
     * Every restaurant, and whether it is actually open.
     */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'kyc' => ['sometimes', 'nullable', 'in:'.implode(',', array_column(KycStatus::cases(), 'value'))],
            'state' => ['sometimes', 'nullable', 'in:accepting,paused,open,closed,blocked'],
            'city' => ['sometimes', 'nullable', 'string', 'max:60'],
            'sort' => ['sometimes', 'in:'.implode(',', array_keys(self::SORTS))],
            'dir' => ['sometimes', 'in:asc,desc'],
        ]);

        $sort = $filters['sort'] ?? 'name';

        // Whitelisted by the rules above, which is what makes it safe to
        // interpolate — an order direction cannot be a bound parameter.
        $dir = $filters['dir'] ?? ($sort === 'name' ? 'asc' : 'desc');

        $term = trim((string) ($filters['q'] ?? ''));
        $kyc = $filters['kyc'] ?? null;
        $state = $filters['state'] ?? null;
        $city = $filters['city'] ?? null;

        $query = Merchant::query()
            ->with(['user:id,name,phone,email,status'])
            ->withCount([
                'orders as orders_total',
                'orders as orders_rejected' => fn ($q) => $q->where('status', OrderStatus::Rejected->value),
                'menuItems as menu_items_total',
                'menuItems as menu_items_unavailable' => fn ($q) => $q->where('is_available', false),
            ]);

        if ($term !== '') {
            $query->where(function ($outer) use ($term) {
                $like = '%'.$term.'%';

                $outer->where('business_name', 'like', $like)
                    ->orWhere('owner_name', 'like', $like)
                    ->orWhere('business_phone', 'like', $like)
                    ->orWhere('fssai_license_no', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhereHas('user', fn ($q) => $q
                        ->where('phone', 'like', $like)
                        ->orWhere('email', 'like', $like));

                if (ctype_digit($term)) {
                    $outer->orWhere('id', (int) $term);
                }
            });
        }

        if ($kyc !== null) {
            $query->where('kyc_status', $kyc);
        }

        if ($city !== null) {
            $query->where('city', $city);
        }

        /*
         * `accepting` and `paused` are the switch in the restaurant's own
         * hands. `open` and `closed` are the clock, which is a different
         * question — a kitchen can be paused at noon or simply shut at
         * midnight, and an admin chasing an order needs to know which.
         *
         * The clock cannot be asked in SQL: opening hours are rows, and
         * `isOpenNow()` reads them in PHP. So those two filter after the
         * query rather than inside it, which is fine at this roster size
         * and honest about costing a little more.
         */
        if ($state === 'accepting') {
            $query->where('is_accepting_orders', true);
        } elseif ($state === 'paused') {
            $query->where('is_accepting_orders', false);
        }

        if ($sort === 'rejects') {
            /*
             * A restaurant with nothing to reject sorts below zero rather
             * than at it, so a worst-first list does not open with whoever
             * signed up yesterday.
             */
            $query->orderByRaw(
                "CASE WHEN orders_total > 0 THEN (orders_rejected * 1.0 / orders_total) ELSE -1 END {$dir}"
            );
        } else {
            $query->orderBy(self::SORTS[$sort], $dir);
        }

        $merchants = $query->paginate(25)->withQueryString();

        if (in_array($state, ['open', 'closed', 'blocked'], true)) {
            $merchants->setCollection($merchants->getCollection()->filter(
                fn (Merchant $m) => match ($state) {
                    'open' => $m->isOpenNow(),
                    'closed' => ! $m->isOpenNow(),
                    default => $m->fssaiBlocker() !== null,
                }
            )->values());
        }

        return view('admin.merchants.index', [
            'merchants' => $merchants,
            'counts' => $this->rosterCounts(),
            'cities' => Merchant::query()->whereNotNull('city')->distinct()->orderBy('city')->pluck('city'),
            'filters' => [
                'q' => $term,
                'kyc' => $kyc,
                'state' => $state,
                'city' => $city,
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    /**
     * One restaurant, whole.
     */
    public function show(Request $request, Merchant $merchant): View
    {
        $data = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $merchant->load(['user:id,name,phone,email,status,created_at', 'zone:id,name']);

        // The last week, which is how a small restaurant thinks about takings
        // and short enough to keep the page quick.
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        $from = isset($data['from'])
            ? Carbon::parse($data['from'])->startOfDay()
            : $to->copy()->subDays(6)->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return view('admin.merchants.show', [
            'merchant' => $merchant,

            /*
             * The same documents and the same actions as the verification
             * queue. This page took over that URL for merchants, so it has
             * to carry the whole job and not just the readable half of it.
             */
            'documents' => $merchant->kycDocuments()->latest('id')->get(),
            'required' => config('kyc.required.merchant'),

            'state' => $this->state($merchant),
            'counts' => $this->orderCounts($merchant),
            'orders' => $this->orders($merchant),
            'performance' => $this->performance($merchant),

            // Straight from the service behind the merchant's own earnings
            // page, so the two screens cannot disagree.
            'earnings' => $this->earnings->summary($merchant, $from, $to),
            'from' => $from,
            'to' => $to,

            'sessions' => $this->sessions($merchant),
            'menu' => $this->menu($merchant),
        ]);
    }

    /**
     * Can this kitchen take an order this minute, and if not, why not.
     *
     * Three separate reasons that look identical from outside — the switch
     * is off, the clock says shut, or the food licence is expired — and an
     * admin phoned about a restaurant that "isn't working" needs to know
     * which one before they can say anything useful.
     *
     * @return array<string, mixed>
     */
    protected function state(Merchant $merchant): array
    {
        return [
            'accepting' => (bool) $merchant->is_accepting_orders,
            'open_now' => $merchant->isOpenNow(),
            'can_receive' => $merchant->canReceiveOrders(),
            'fssai_blocker' => $merchant->fssaiBlocker(),
            'verified' => $merchant->isKycVerified(),
            'commission_rate' => $merchant->effectiveCommissionRate(),
            'upcoming_change' => $merchant->hasUpcomingCommissionChange(),
        ];
    }

    /** @return array<string, int> */
    protected function orderCounts(Merchant $merchant): array
    {
        $byStatus = $merchant->orders()
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $finished = Order::finishedStatuses();

        return [
            'total' => (int) $byStatus->sum(),
            'delivered' => (int) ($byStatus[OrderStatus::Delivered->value] ?? 0),
            'rejected' => (int) ($byStatus[OrderStatus::Rejected->value] ?? 0),
            'cancelled' => (int) ($byStatus[OrderStatus::Cancelled->value] ?? 0),
            'ongoing' => (int) $byStatus
                ->reject(fn ($n, $status) => in_array($status, $finished, true))
                ->sum(),
        ];
    }

    protected function orders(Merchant $merchant): LengthAwarePaginator
    {
        return $merchant->orders()
            ->with(['customer:id,name', 'rider:id,full_name'])
            ->latest('id')
            // Its own page name, so paging orders does not reset the
            // earnings window the admin chose.
            ->paginate(15, ['*'], 'orders')
            ->withQueryString();
    }

    /**
     * Whether this is a restaurant worth having.
     *
     * Two numbers here exist nowhere else on the platform.
     *
     * **Rejection rate.** A restaurant that accepts a customer and then
     * rejects the order costs a customer, not an order — and nothing counted
     * it per restaurant before, so a bad one stayed invisible until somebody
     * noticed a pattern by hand.
     *
     * **Prep time, promised against actual.** Every customer ETA is built on
     * the estimate; when it is wrong, every ETA for that restaurant is wrong
     * in the same direction all day. It is also the figure `docs/DISPATCH.md`
     * names as the thing missing before dispatch can start hunting a rider at
     * (prep − travel) instead of at acceptance.
     *
     * Averaged in PHP over recent orders rather than in SQL, for the same
     * reasons as the rider page: `TIMESTAMPDIFF` is MySQL's and the suite
     * runs on SQLite, and recent is the more useful question than lifetime.
     *
     * @return array<string, mixed>
     */
    protected function performance(Merchant $merchant): array
    {
        $recent = $merchant->orders()
            ->where('status', OrderStatus::Delivered->value)
            ->whereNotNull('accepted_at')
            ->latest('accepted_at')
            ->limit(self::SAMPLE)
            ->get(['placed_at', 'accepted_at', 'ready_at', 'estimated_prep_minutes']);

        $actualPrep = $this->averageMinutes($recent, 'accepted_at', 'ready_at');

        $promised = $recent
            ->filter(fn (Order $o) => $o->estimated_prep_minutes !== null && $o->ready_at !== null)
            ->avg('estimated_prep_minutes');

        $counts = $this->orderCounts($merchant);

        /*
         * Rejections over everything the kitchen was actually asked to make.
         * Orders that never reached them — abandoned at payment — are not
         * the restaurant's doing and would quietly flatter the rate.
         */
        $asked = $counts['total'] - (int) $merchant->orders()
            ->where('status', OrderStatus::PendingPayment->value)
            ->count();

        return [
            'rating' => $merchant->rating === null ? null : (float) $merchant->rating,
            'rating_count' => (int) $merchant->rating_count,
            'orders_asked' => max(0, $asked),
            'rejected' => $counts['rejected'],
            'rejection_rate' => $asked > 0 ? round($counts['rejected'] / $asked * 100, 1) : null,
            'sample' => $recent->count(),
            'minutes_to_accept' => $this->averageMinutes($recent, 'placed_at', 'accepted_at'),
            'minutes_prep_actual' => $actualPrep,
            'minutes_prep_promised' => $promised === null ? null : round((float) $promised, 1),
            'prep_gap' => ($actualPrep === null || $promised === null)
                ? null
                : round($actualPrep - (float) $promised, 1),
            'prep_setting' => $merchant->avg_prep_time_minutes,
        ];
    }

    /**
     * Mean minutes between two timestamps, across the rows that have both.
     *
     * Null when nothing qualifies, which the view draws as a dash — a zero
     * would read as "instant" rather than "never measured".
     *
     * @param  Collection<int, Order>  $orders
     */
    protected function averageMinutes(Collection $orders, string $from, string $to): ?float
    {
        $spans = $orders
            ->filter(fn (Order $order) => $order->{$from} !== null && $order->{$to} !== null)
            ->map(fn (Order $order) => $order->{$from}->diffInSeconds($order->{$to}))
            // A negative span is a clock or a backfill, not a kitchen that
            // finished before it started.
            ->filter(fn (int $seconds) => $seconds >= 0);

        return $spans->isEmpty() ? null : round($spans->avg() / 60, 1);
    }

    /**
     * Sign-ins and activity, from the refresh tokens.
     *
     * Same source and same subtlety as the rider page: refreshing rotates
     * the token, so a row is a real sign-in only when no other row was
     * replaced by it. For a restaurant this answers a question support asks
     * often — is anybody actually logged into the merchant portal, or has
     * the tablet in the kitchen been signed out since Tuesday.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function sessions(Merchant $merchant): Collection
    {
        $tokens = RefreshToken::query()
            ->where('user_id', $merchant->user_id)
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
            'is_sign_in' => ! in_array($token->id, $rotatedInto, true),
        ]);
    }

    /**
     * Menu health.
     *
     * A restaurant showing on the home screen with every dish marked out of
     * stock is open, verified, accepting orders and completely unorderable —
     * and looks perfectly healthy on every other screen in this portal.
     *
     * @return array<string, mixed>
     */
    protected function menu(Merchant $merchant): array
    {
        $total = $merchant->menuItems()->count();
        $available = $merchant->menuItems()->where('is_available', true)->count();

        return [
            'total' => $total,
            'available' => $available,
            'unavailable' => $total - $available,
            'empty' => $total === 0,
            'nothing_orderable' => $total > 0 && $available === 0,
        ];
    }

    /** How the roster splits, for the filter chips. */
    protected function rosterCounts(): Collection
    {
        $all = Merchant::query()->get(['id', 'is_accepting_orders', 'kyc_status']);

        return collect([
            'all' => $all->count(),
            'accepting' => $all->where('is_accepting_orders', true)->count(),
            'paused' => $all->where('is_accepting_orders', false)->count(),
            'unverified' => $all->filter(fn (Merchant $m) => $m->kyc_status !== KycStatus::Verified)->count(),
        ]);
    }
}
