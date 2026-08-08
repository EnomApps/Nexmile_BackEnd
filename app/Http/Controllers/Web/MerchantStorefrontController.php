<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\Media\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Storefront presentation and opening hours in the merchant portal (EP3).
 *
 * Both decide what a customer sees on the home screen: the logo is the first
 * thing they look at, and the hours decide whether the shop appears open.
 */
class MerchantStorefrontController extends Controller
{
    private const IMAGE_COLUMNS = [
        'logo' => 'logo_path',
        'banner' => 'banner_path',
    ];

    /** Sunday first, matching Carbon's dayOfWeek. */
    public const DAYS = [
        0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
    ];

    public function __construct(protected ImageService $images) {}

    public function edit(Request $request): View
    {
        $merchant = $this->merchant($request);

        return view('merchants.storefront', [
            'merchant' => $merchant,
            'logoUrl' => $this->images->url($merchant->logo_path),
            'bannerUrl' => $this->images->url($merchant->banner_path),
            'hours' => $merchant->operatingHours()->get()->keyBy('day_of_week'),
            'days' => self::DAYS,
        ]);
    }

    public function uploadImage(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(self::IMAGE_COLUMNS))],
            'file' => ImageService::rules(),
        ], ImageService::messages('file'));

        $merchant = $this->merchant($request);

        $this->images->attach(
            $merchant,
            self::IMAGE_COLUMNS[$data['type']],
            'storefront/'.$merchant->id,
            $request->file('file'),
        );

        return back()->with('status', __('portal.storefront.image_saved'));
    }

    public function destroyImage(Request $request, string $type): RedirectResponse
    {
        abort_unless(isset(self::IMAGE_COLUMNS[$type]), 404);

        $this->images->detach($this->merchant($request), self::IMAGE_COLUMNS[$type]);

        return back()->with('status', __('portal.storefront.image_removed'));
    }

    /**
     * The whole week is posted at once. A schedule is edited as a unit, and a
     * partial write could leave the shop open on a day just marked closed.
     */
    public function saveHours(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['required', 'array'],
            'days.*.is_open' => ['sometimes', 'boolean'],
            'days.*.opens_at' => ['nullable', 'date_format:H:i'],
            'days.*.closes_at' => ['nullable', 'date_format:H:i'],
        ], [
            'days.*.opens_at.date_format' => __('portal.storefront.time_format'),
            'days.*.closes_at.date_format' => __('portal.storefront.time_format'),
        ]);

        $merchant = $this->merchant($request);

        DB::transaction(function () use ($merchant, $data) {
            $merchant->operatingHours()->delete();

            foreach (array_keys(self::DAYS) as $day) {
                $row = $data['days'][$day] ?? [];
                $open = (bool) ($row['is_open'] ?? false);

                $merchant->operatingHours()->create([
                    'day_of_week' => $day,
                    'is_closed' => ! $open,
                    /*
                     * Times are kept even for a closed day, so reopening it
                     * restores what the merchant last used rather than blanks.
                     *
                     * `??` before `?:` because a day the form did not submit
                     * at all has no key, not an empty one.
                     */
                    'opens_at' => ($row['opens_at'] ?? null) ?: '09:00',
                    'closes_at' => ($row['closes_at'] ?? null) ?: '22:00',
                ]);
            }
        });

        return back()->with('status', __('portal.storefront.hours_saved'));
    }

    protected function merchant(Request $request): Merchant
    {
        $merchant = $request->user()->merchant;

        abort_if($merchant === null, 404);

        return $merchant;
    }
}
