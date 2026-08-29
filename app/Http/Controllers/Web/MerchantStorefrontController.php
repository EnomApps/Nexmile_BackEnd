<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Cuisine;
use App\Models\Merchant;
use App\Services\Media\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
            'cuisineChoices' => Cuisine::live()->get(),
            'photos' => $merchant->photos()->get(),
            'photoLimit' => (int) config('media.max_storefront_photos'),
            'images' => $this->images,
        ]);
    }

    /**
     * How the restaurant is listed and filtered.
     *
     * Set by the merchant rather than by us: they know whether their kitchen
     * is pure veg and what two people actually spend. Without these three the
     * cuisine rail, the VEG toggle and the price filters all match nothing —
     * they look broken when they are only unset.
     */
    public function saveListing(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'is_pure_veg' => ['sometimes', 'boolean'],
            'cost_for_two' => ['nullable', 'integer', 'between:1,10000'],
            'cuisines' => ['nullable', 'array', 'max:6'],
            // Slugs, matched against the cuisines an admin has configured.
            // Anything else would filter to nothing, silently.
            'cuisines.*' => ['string', Rule::exists('cuisines', 'slug')],
        ], [
            'cuisines.max' => __('portal.storefront.cuisines_max'),
        ]);

        $merchant = $this->merchant($request);

        /*
         * The other half of the same rule enforced on a dish: a kitchen cannot
         * declare itself pure veg while non-veg dishes are on its menu.
         *
         * Named rather than counted. "You have 3 non-veg dishes" sends the
         * merchant hunting; naming them is the difference between a refusal
         * they can act on and one they argue with.
         */
        if (($data['is_pure_veg'] ?? false) && ! $merchant->is_pure_veg) {
            $nonVeg = $merchant->menuItems()->where('is_veg', false)->pluck('name');

            if ($nonVeg->isNotEmpty()) {
                return back()->withErrors([
                    'is_pure_veg' => __('portal.menu.veg_conflict_menu', [
                        'dishes' => $nonVeg->take(5)->implode(', ')
                            .($nonVeg->count() > 5 ? __('portal.menu.and_more', ['count' => $nonVeg->count() - 5]) : ''),
                    ]),
                ])->withInput();
            }
        }

        $merchant->update([
            'is_pure_veg' => (bool) ($data['is_pure_veg'] ?? false),
            'cost_for_two' => $data['cost_for_two'] ?? null,
            'cuisines' => array_values($data['cuisines'] ?? []),
        ]);

        return back()->with('status', __('portal.storefront.listing_saved'));
    }

    /**
     * Add a photo to the storefront carousel.
     *
     * One banner heads a page; it does not sell a place. A customer deciding
     * between two kitchens they have never visited wants the room, the
     * counter, the food going out.
     */
    public function uploadPhoto(Request $request, ImageService $images): RedirectResponse
    {
        $data = $request->validate([
            'file' => ImageService::rules(),
            'caption' => ['sometimes', 'nullable', 'string', 'max:120'],
        ], ImageService::messages('file'));

        $merchant = $this->merchant($request);
        $limit = (int) config('media.max_storefront_photos');

        /*
         * A cap, because a carousel nobody swipes to the end of is a carousel
         * that costs data for nothing — and every slide is a signed URL the
         * app fetches on open.
         */
        if ($merchant->photos()->count() >= $limit) {
            return back()->withErrors([
                'file' => __('portal.storefront.photos_full', ['limit' => $limit]),
            ]);
        }

        /*
         * The file is stored before the row exists, for the same reason
         * banners are: image_path is NOT NULL, and a row created first has
         * nothing to put there. A failed upload should leave no photo rather
         * than a broken one — or, as here, a constraint violation.
         */
        $stored = $images->store('storefront/'.$merchant->id, $request->file('file'));

        $photo = $merchant->photos()->make([
            'caption' => $data['caption'] ?? null,
            // Appended, not inserted: a new photo joining the middle of a
            // carousel the merchant has already arranged is a surprise.
            'position' => (int) $merchant->photos()->max('position') + 1,
        ]);

        // forceFill, because image_path is deliberately not mass assignable.
        $photo->forceFill(['image_path' => $stored])->save();

        return back()->with('status', __('portal.storefront.photo_saved'));
    }

    public function destroyPhoto(Request $request, int $photo, ImageService $images): RedirectResponse
    {
        $model = $this->merchant($request)->photos()->findOrFail($photo);

        // purge, not detach: the record is going away, and image_path cannot
        // hold null.
        $images->purge($model->image_path);
        $model->delete();

        return back()->with('status', __('portal.storefront.photo_removed'));
    }

    /**
     * Move a photo one place earlier or later.
     *
     * The first slide is the one most people see, so which photo leads is the
     * only ordering decision that really matters — and it is two clicks away
     * rather than a drag interaction to build and maintain.
     */
    public function movePhoto(Request $request, int $photo): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ]);

        $merchant = $this->merchant($request);
        $photos = $merchant->photos()->get();

        $index = $photos->search(fn ($p) => $p->id === $photo);

        abort_if($index === false, 404);

        $swapWith = $data['direction'] === 'up' ? $index - 1 : $index + 1;

        if ($swapWith < 0 || $swapWith >= $photos->count()) {
            return back();
        }

        $ordered = $photos->values()->all();

        [$ordered[$index], $ordered[$swapWith]] = [$ordered[$swapWith], $ordered[$index]];

        /*
         * The whole sequence is renumbered, not just the two rows swapped.
         * Positions drift after deletions and two rows can end up sharing one,
         * where swapping a pair of duplicates does nothing and reads as a
         * broken button.
         */
        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $i => $photo) {
                $photo->forceFill(['position' => $i + 1])->save();
            }
        });

        return back();
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
