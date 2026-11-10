<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement in or out of a wallet.
 *
 * Written once and never edited. A ledger that can be corrected in place is
 * not a ledger — a mistake is fixed by another row, so the history still adds
 * up to the balance.
 */
class WalletTransaction extends Model
{
    public const TOPUP = 'topup';

    public const ORDER_PAYMENT = 'order_payment';

    public const REFUND = 'refund';

    /** Ops putting something right. Always explained in `description`. */
    public const ADJUSTMENT = 'adjustment';

    public const CREDIT = 'credit';

    public const DEBIT = 'debit';

    protected $fillable = [
        'wallet_id', 'type', 'direction', 'amount', 'balance_after',
        'order_id', 'wallet_topup_id', 'description', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Signed, for adding the ledger back up. */
    public function signedAmount(): float
    {
        return $this->direction === self::CREDIT
            ? (float) $this->amount
            : -(float) $this->amount;
    }
}
