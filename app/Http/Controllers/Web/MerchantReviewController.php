<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What customers said, for the kitchen that cooked it.
 *
 * A restaurant could be rated but never read its ratings, which left a score
 * dropping with no way to find out why. A number a merchant cannot explain is
 * a number they cannot act on.
 */
class MerchantReviewController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'stars' => ['sometimes', 'integer', 'between:1,5'],
        ]);

        $merchant = $this->merchant($request);

        $reviews = $merchant->reviews()
            ->visible()
            ->with(['user:id,name', 'items.menuItem:id,name', 'order:id,order_number'])
            ->when($data['stars'] ?? null, fn ($q, $stars) => $q->where('rating', $stars))
            /*
             * Newest first, not worst first. A merchant opening this page
             * wants to know what happened today; sorting by anger would make
             * one bad night permanent at the top.
             */
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $counts = $merchant->reviews()->visible()
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        return view('merchants.reviews', [
            'merchant' => $merchant,
            'reviews' => $reviews,
            'breakdown' => collect([5, 4, 3, 2, 1])
                ->mapWithKeys(fn ($star) => [$star => (int) ($counts[$star] ?? 0)]),
            'stars' => $data['stars'] ?? null,

            /*
             * The dishes people rate worst, which is the one thing on this
             * page a kitchen can act on this evening.
             */
            'weakest' => $merchant->menuItems()
                ->whereNotNull('rating')
                ->orderBy('rating')
                ->limit(5)
                ->get(['id', 'name', 'rating', 'rating_count']),
        ]);
    }

    private function merchant(Request $request): Merchant
    {
        $merchant = $request->user()->merchant;

        abort_if($merchant === null, 403);

        return $merchant;
    }
}
