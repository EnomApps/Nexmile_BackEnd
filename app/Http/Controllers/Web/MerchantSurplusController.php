<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Services\Menu\SurplusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Food Rescue in the merchant portal (EP14).
 *
 * Its own page rather than a section of the dish form, because a merchant
 * reaches for this at the end of service with a specific question — "what is
 * left and what can I shift" — and that is a different task from editing a
 * menu.
 */
class MerchantSurplusController extends Controller
{
    public function __construct(protected SurplusService $surplus) {}

    public function index(Request $request): View
    {
        $merchant = $this->merchant($request);

        $items = $merchant->menuItems()->with('category')->ordered()->get();

        return view('merchants.surplus', [
            'merchant' => $merchant,
            'deals' => $items->filter(fn (MenuItem $i) => $i->is_surplus_deal)->values(),
            'candidates' => $items->reject(fn (MenuItem $i) => $i->is_surplus_deal)->values(),
            'surplus' => $this->surplus,
        ]);
    }

    public function store(Request $request, int $item): RedirectResponse
    {
        $data = $request->validate([
            'price' => ['required', 'numeric', 'between:1,99999.99'],
            'compare_at_price' => ['sometimes', 'nullable', 'numeric', 'between:1,99999.99'],
            'surplus_quantity' => ['required', 'integer', 'between:1,500'],
            'surplus_available_from' => ['required', 'date'],
            'surplus_available_until' => ['required', 'date'],
        ], [
            'surplus_quantity.between' => __('portal.surplus.quantity_range'),
        ]);

        $this->surplus->offer($this->item($request, $item), $data);

        return back()->with('status', __('portal.surplus.offered'));
    }

    public function destroy(Request $request, int $item): RedirectResponse
    {
        $this->surplus->withdraw($this->item($request, $item));

        return back()->with('status', __('portal.surplus.withdrawn'));
    }

    protected function item(Request $request, int $item): MenuItem
    {
        return $this->merchant($request)->menuItems()->findOrFail($item);
    }

    protected function merchant(Request $request): Merchant
    {
        $merchant = $request->user()->merchant;

        abort_if($merchant === null, 404);

        return $merchant;
    }
}
