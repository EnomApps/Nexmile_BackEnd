<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Api\V1\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantResource;
use App\Services\Media\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * How the storefront looks and when it is open (EP3, EP4).
 *
 * Both feed the customer's home screen: the logo is the first thing they see,
 * and the hours decide whether the shop appears open at all.
 */
class StorefrontController extends Controller
{
    use ResolvesMerchant;

    /** The columns a merchant may upload to, and where each is stored. */
    private const IMAGE_COLUMNS = [
        'logo' => 'logo_path',
        'banner' => 'banner_path',
    ];

    public function __construct(protected ImageService $images) {}

    /**
     * Upload a logo or banner
     *
     * Multipart: `type` (logo or banner) and `file`.
     */
    public function uploadImage(Request $request): JsonResponse
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

        return response()->json([
            'message' => ucfirst($data['type']).' updated.',
            'data' => new MerchantResource($merchant->fresh()),
        ]);
    }

    /**
     * Remove a logo or banner
     */
    public function destroyImage(Request $request, string $type): JsonResponse
    {
        abort_unless(isset(self::IMAGE_COLUMNS[$type]), 404);

        $merchant = $this->merchant($request);

        $this->images->detach($merchant, self::IMAGE_COLUMNS[$type]);

        return response()->json([
            'message' => ucfirst($type).' removed.',
            'data' => new MerchantResource($merchant->fresh()),
        ]);
    }

    /**
     * Opening hours
     */
    public function hours(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->hoursPayload($request),
        ]);
    }

    /**
     * Replace the weekly schedule
     *
     * The whole week is sent at once. A schedule is edited as a unit, and a
     * partial write could leave a shop open on a day the merchant just closed.
     *
     * `day_of_week` is 0 for Sunday through 6 for Saturday, matching Carbon.
     * Days omitted entirely are treated as closed.
     */
    public function setHours(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['required', 'array', 'max:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'hours.*.is_closed' => ['sometimes', 'boolean'],
            /*
             * All or nothing. A day sent with a closing time but no opening
             * one would otherwise be silently filed as closed, which is not
             * what a merchant who typed half a row meant.
             *
             * Omitting both is the documented way to say "closed".
             */
            'hours.*.opens_at' => ['nullable', 'required_with:hours.*.closes_at', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'required_with:hours.*.opens_at', 'date_format:H:i'],
        ], [
            'hours.*.opens_at.date_format' => 'Use 24-hour times like 09:00 and 22:30.',
            'hours.*.closes_at.date_format' => 'Use 24-hour times like 09:00 and 22:30.',
        ]);

        $days = collect($data['hours'])->keyBy('day_of_week');

        abort_if(
            $days->count() !== count($data['hours']),
            422,
            'Each day may only appear once.',
        );

        $merchant = $this->merchant($request);

        DB::transaction(function () use ($merchant, $days) {
            $merchant->operatingHours()->delete();

            foreach ($days as $day => $row) {
                $closed = (bool) ($row['is_closed'] ?? false) || empty($row['opens_at']);

                $merchant->operatingHours()->create([
                    'day_of_week' => $day,
                    'is_closed' => $closed,
                    // Kept even when closed so reopening a day restores the
                    // times the merchant last used, rather than blanks.
                    'opens_at' => $row['opens_at'] ?? '09:00',
                    'closes_at' => $row['closes_at'] ?? '22:00',
                ]);
            }
        });

        return response()->json([
            'message' => 'Opening hours saved.',
            'data' => $this->hoursPayload($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function hoursPayload(Request $request): array
    {
        $merchant = $this->merchant($request);

        return [
            'is_open_now' => $merchant->isOpenNow(),
            'within_operating_hours' => $merchant->isWithinOperatingHours(),
            'hours' => $merchant->operatingHours()->orderBy('day_of_week')->get()
                ->map(fn ($row) => [
                    'day_of_week' => (int) $row->day_of_week,
                    'is_closed' => (bool) $row->is_closed,
                    'opens_at' => substr((string) $row->opens_at, 0, 5),
                    'closes_at' => substr((string) $row->closes_at, 0, 5),
                ])->values(),
        ];
    }
}
