<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Merchant;
use Illuminate\Http\Request;

/**
 * Every merchant-owned query starts from the authenticated merchant, never
 * from an id in the URL. Menu items and orders are addressed by primary key,
 * so scoping through the relationship is what turns another merchant's id
 * into a 404 instead of a data leak.
 */
trait ResolvesMerchant
{
    protected function merchant(Request $request): Merchant
    {
        $merchant = $request->user()?->merchant;

        abort_if($merchant === null, 404, 'No business profile found for this account.');

        return $merchant;
    }
}
