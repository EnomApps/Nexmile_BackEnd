<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** Gateway payloads can contain card metadata; never serialise them. */
    protected $hidden = ['gateway_payload', 'gateway_signature'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'gateway_payload' => 'array',
            'authorised_at' => 'datetime',
            'captured_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    public function refundedAmount(): float
    {
        return (float) $this->refunds->where('status', 'completed')->sum('amount');
    }

    public function refundableAmount(): float
    {
        return max(0, (float) $this->amount - $this->refundedAmount());
    }
}
