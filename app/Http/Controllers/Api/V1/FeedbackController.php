<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Rate the app", from the customer profile page.
 *
 * Not a review of a restaurant, a rider or an order — those have their own
 * tables and their own consequences. This is about the software.
 */
class FeedbackController extends Controller
{
    /**
     * Send feedback about the app
     *
     * A rating is required; everything else is optional. The build and
     * platform are worth asking for because a run of one-star ratings that is
     * all one version on one platform is a release regression, while the same
     * spread evenly is an opinion.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'message' => ['nullable', 'string', 'max:1000'],

            /*
             * Both nullable rather than required. Losing a customer's
             * sentence because their build forgot to name itself is a worse
             * trade than storing a row with an unknown platform.
             */
            'platform' => ['nullable', 'in:android,ios'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ], [
            'rating.required' => 'Choose a rating from 1 to 5.',
            'rating.between' => 'Choose a rating from 1 to 5.',
            'message.max' => 'Keep it under 1000 characters.',
        ]);

        $feedback = AppFeedback::create($data + ['user_id' => $request->user()->id]);

        return response()->json([
            'message' => 'Thank you — your feedback has been sent.',
            'data' => ['id' => $feedback->id],
        ], 201);
    }
}
