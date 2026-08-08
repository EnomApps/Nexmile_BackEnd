<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A merchant editing their own details (EP2, EP3).
 *
 * The API has had this since EP2; the portal never did, so a merchant could
 * not correct their own phone number — or, more seriously, their location.
 *
 * KYC fields are absent by design: letting a verified merchant edit their own
 * FSSAI number would make verification meaningless. Commission is absent for
 * the same reason it is not mass-assignable — it is a contract term.
 */
class MerchantProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('merchants.profile', ['merchant' => $this->merchant($request)]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'business_phone' => ['nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            'business_email' => ['nullable', 'email', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],

            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'pincode' => ['required', 'string', 'regex:/^[1-9]\d{5}$/'],

            /*
             * Required, not optional. Delivery is capped at 1 km and matching
             * is done on coordinates — a merchant without them is invisible to
             * every customer, and cannot work out why.
             */
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],

            'avg_prep_time_minutes' => ['required', 'integer', 'between:1,120'],
            'packaging_fee' => ['required', 'numeric', 'between:0,500'],
            'min_order_value' => ['required', 'numeric', 'between:0,10000'],
            'supports_pickup' => ['sometimes', 'boolean'],
        ], [
            'business_phone.regex' => __('portal.profile.phone_invalid'),
            'pincode.regex' => __('portal.profile.pincode_invalid'),
            'latitude.required' => __('portal.profile.location_required'),
            'longitude.required' => __('portal.profile.location_required'),
        ]);

        $this->merchant($request)->update($data);

        return back()->with('status', __('portal.profile.saved'));
    }

    protected function merchant(Request $request): Merchant
    {
        $merchant = $request->user()->merchant;

        abort_if($merchant === null, 404);

        return $merchant;
    }
}
