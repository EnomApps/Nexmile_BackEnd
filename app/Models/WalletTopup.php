<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money on its way into a wallet, and how it fared at the gateway. */
class WalletTopup extends Model
{
    public const CREATED = 'created';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    protected $fillable = [
        'user_id', 'amount', 'currency', 'status', 'gateway',
        'gateway_order_id', 'gateway_payment_id', 'gateway_signature',
        'gateway_payload', 'failure_reason', 'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gateway_payload' => 'array',
            'credited_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Already credited?
     *
     * Read inside the lock, never outside it. Checked outside, two concurrent
     * callers both see false and both credit.
     */
    public function isCredited(): bool
    {
        return $this->credited_at !== null;
    }
}
