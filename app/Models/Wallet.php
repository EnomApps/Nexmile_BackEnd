<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's balance.
 *
 * The balance lives here rather than being summed from the ledger on every
 * read, because a spend has to lock something and a SUM cannot be locked
 * cheaply. WalletService owns every change to it — nothing else should ever
 * write `balance`.
 */
class Wallet extends Model
{
    protected $fillable = ['user_id', 'balance'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /** Can this wallet cover that much? */
    public function covers(float $amount): bool
    {
        return (float) $this->balance >= $amount;
    }
}
