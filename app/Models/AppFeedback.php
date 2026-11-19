<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's rating of the app itself.
 */
class AppFeedback extends Model
{
    protected $table = 'app_feedback';

    protected $fillable = [
        'user_id', 'rating', 'message', 'platform', 'app_version',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    /** Null once the customer has closed their account. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
