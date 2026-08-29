<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Collection;
use App\Models\Cuisine;
use App\Models\Merchant;
use App\Services\Media\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The customer home screen, edited by a person rather than by SQL.
 *
 * These tables were built so merchandising could change without an app
 * release. Without this screen they only moved the release from the Play Store
 * to a database client, which is worse: it needs an engineer, at night, with
 * production credentials.
 */
class MerchandisingController extends Controller
{
    /** Where a banner tap can go. Mirrors what the customer app routes. */
    private const ACTIONS = ['none', 'restaurant', 'collection', 'cuisine', 'url'];

    public function index(): View
    {
        return view('admin.merchandising', [
            'banners' => Banner::orderBy('position')->get(),
            'cuisines' => Cuisine::orderBy('position')->get(),
            'collections' => Collection::with('merchants:id,business_name')->orderBy('position')->get(),
            'restaurants' => Merchant::where('kyc_status', KycStatus::Verified->value)
                ->orderBy('business_name')
                ->get(['id', 'business_name']),
            'actions' => self::ACTIONS,
            'images' => app(ImageService::class),
        ]);
    }

    public function storeBanner(Request $request, ImageService $images): RedirectResponse
    {
        $data = $request->validate([
            'image' => ['required', 'image', 'max:4096'],
            'alt_text' => ['required', 'string', 'max:120'],
            'action_type' => ['required', Rule::in(self::ACTIONS)],
            // Required for every action except "none", which needs no target.
            'action_value' => ['nullable', 'required_unless:action_type,none', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'position' => ['nullable', 'integer', 'min:0'],
        ], [
            'alt_text.required' => 'Alt text is required — a screen reader has nothing else to announce.',
            'ends_at.after' => 'The end of a campaign has to come after its start.',
        ]);

        /*
         * The file is stored before the row exists.
         *
         * Creating the banner first with an empty path and attaching after
         * meant a failed upload left a permanent banner pointing at nothing —
         * active, inside its dates, and rendering as a hole in the carousel.
         * Now a failed write means no banner, which is the honest outcome.
         */
        $stored = $images->store('banners', $request->file('image'));

        Banner::create([
            'image_path' => $stored,
            'alt_text' => $data['alt_text'],
            'action_type' => $data['action_type'],
            'action_value' => $data['action_type'] === 'none' ? null : $data['action_value'],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'position' => $data['position'] ?? 0,
        ]);

        return back()->with('status', 'Banner added.');
    }

    public function toggleBanner(Banner $banner): RedirectResponse
    {
        $banner->update(['is_active' => ! $banner->is_active]);

        return back()->with('status', $banner->is_active ? 'Banner switched on.' : 'Banner switched off.');
    }

    public function destroyBanner(Banner $banner, ImageService $images): RedirectResponse
    {
        // Drop the file too: an orphaned image is a bill nobody is watching.
        $images->detach($banner, 'image_path');
        $banner->delete();

        return back()->with('status', 'Banner removed.');
    }

    public function storeCuisine(Request $request, ImageService $images): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            /*
             * The slug is what a restaurant's `cuisines` list has to match and
             * what the app sends back as a filter, so it is lowercase and
             * hyphenated by rule rather than by whoever is typing.
             */
            'slug' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', 'unique:cuisines,slug'],
            'image' => ['nullable', 'image', 'max:2048'],
            'position' => ['nullable', 'integer', 'min:0'],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and hyphens — for example "south-indian".',
        ]);

        $cuisine = Cuisine::create([
            'slug' => $data['slug'],
            'name' => $data['name'],
            'position' => $data['position'] ?? 0,
        ]);

        if ($request->hasFile('image')) {
            $images->attach($cuisine, 'image_path', 'cuisines', $request->file('image'));
        }

        return back()->with('status', 'Cuisine added.');
    }

    /**
     * Add or replace a cuisine icon after the fact.
     *
     * The icon is optional at creation — a cuisine is useful for filtering
     * before anyone has drawn one. Without this, the only route to an icon was
     * deleting the cuisine and making it again, which breaks every restaurant
     * already filed under that slug.
     */
    public function uploadCuisineImage(Request $request, Cuisine $cuisine, ImageService $images): RedirectResponse
    {
        $request->validate(['image' => ImageService::rules()], ImageService::messages('image'));

        $images->attach($cuisine, 'image_path', 'cuisines', $request->file('image'));

        return back()->with('status', 'Cuisine icon saved.');
    }

    public function destroyCuisineImage(Cuisine $cuisine, ImageService $images): RedirectResponse
    {
        $images->detach($cuisine, 'image_path');

        return back()->with('status', 'Cuisine icon removed.');
    }

    /** Same gap on a collection tile, for the same reason. */
    public function uploadCollectionImage(Request $request, Collection $collection, ImageService $images): RedirectResponse
    {
        $request->validate(['image' => ImageService::rules()], ImageService::messages('image'));

        $images->attach($collection, 'banner_path', 'collections', $request->file('image'));

        return back()->with('status', 'Collection image saved.');
    }

    public function destroyCuisine(Cuisine $cuisine, ImageService $images): RedirectResponse
    {
        $images->detach($cuisine, 'image_path');
        $cuisine->delete();

        return back()->with('status', 'Cuisine removed.');
    }

    public function storeCollection(Request $request, ImageService $images): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', 'unique:collections,slug'],
            'subtitle' => ['nullable', 'string', 'max:160'],
            'image' => ['nullable', 'image', 'max:4096'],
            'position' => ['nullable', 'integer', 'min:0'],
            'show_on_home' => ['sometimes', 'boolean'],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and hyphens — for example "under-250".',
        ]);

        $collection = Collection::create([
            'slug' => $data['slug'],
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? null,
            'position' => $data['position'] ?? 0,
            'show_on_home' => (bool) ($data['show_on_home'] ?? true),
        ]);

        if ($request->hasFile('image')) {
            $images->attach($collection, 'banner_path', 'collections', $request->file('image'));
        }

        return back()->with('status', 'Collection created.');
    }

    public function updateCollectionMerchants(Request $request, Collection $collection): RedirectResponse
    {
        $data = $request->validate([
            'merchant_ids' => ['nullable', 'array'],
            'merchant_ids.*' => ['integer', 'exists:merchants,id'],
        ]);

        /*
         * The order they were picked in is the order they appear. sync() with
         * pivot data in one call, so a reorder is not a delete and re-add that
         * empties the collection if the second half fails.
         */
        $ids = array_values($data['merchant_ids'] ?? []);

        $collection->merchants()->sync(
            array_combine($ids, array_map(fn ($i) => ['position' => $i], array_keys($ids)))
        );

        return back()->with('status', 'Collection updated.');
    }

    public function toggleCollection(Collection $collection): RedirectResponse
    {
        $collection->update(['is_active' => ! $collection->is_active]);

        return back()->with('status', $collection->is_active ? 'Collection switched on.' : 'Collection switched off.');
    }

    public function destroyCollection(Collection $collection, ImageService $images): RedirectResponse
    {
        $images->detach($collection, 'banner_path');
        $collection->delete();

        return back()->with('status', 'Collection removed.');
    }
}
