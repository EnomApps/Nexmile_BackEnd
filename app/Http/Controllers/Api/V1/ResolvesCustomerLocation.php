<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Address;
use Illuminate\Http\Request;

/**
 * Turning "where is the customer" into a point.
 *
 * Shared by discovery and the home screen so both accept the same two forms —
 * a saved `address_id` or raw GPS — and both refuse an address with no pin in
 * the same words. Two copies would drift, and the copy that drifted would be
 * the one a customer hit.
 */
trait ResolvesCustomerLocation
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{float, float}
     */
    protected function resolvePoint(Request $request, array $data): array
    {
        if (isset($data['latitude'], $data['longitude'])) {
            return [(float) $data['latitude'], (float) $data['longitude']];
        }

        // Scoped to the caller: another customer's address id is a 404, not a
        // window onto where they live.
        $address = Address::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($data['address_id']);

        abort_if(
            $address->latitude === null || $address->longitude === null,
            422,
            'This address has no location saved. Edit it and drop a pin.',
        );

        return [(float) $address->latitude, (float) $address->longitude];
    }
}
