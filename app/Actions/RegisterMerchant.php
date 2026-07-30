<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates the owner's user account and the merchant business profile together.
 *
 * Shared by the JSON API (Flutter / React clients) and the Blade signup form so
 * both paths produce identical records.
 */
class RegisterMerchant
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['owner_name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => UserRole::Merchant,
                'status' => UserStatus::Pending,
                'preferred_locale' => $data['preferred_locale'] ?? 'en',
            ]);

            Merchant::create([
                'user_id' => $user->id,
                'business_name' => $data['business_name'],
                'owner_name' => $data['owner_name'],
                'business_phone' => $data['business_phone'] ?? $data['phone'],
                'business_email' => $data['business_email'] ?? $data['email'],
                'address_line1' => $data['address_line1'],
                'address_line2' => $data['address_line2'] ?? null,
                'city' => $data['city'],
                'state' => $data['state'] ?? 'Tamil Nadu',
                'pincode' => $data['pincode'],
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'fssai_license_no' => $data['fssai_license_no'] ?? null,
                'fssai_expiry_date' => $data['fssai_expiry_date'] ?? null,
                'gstin' => $data['gstin'] ?? null,
                'pan' => $data['pan'] ?? null,
            ]);

            return $user->load('merchant');
        });
    }
}
