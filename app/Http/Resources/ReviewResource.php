<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review as a stranger reads it.
 *
 * Deliberately narrower than the row: no order number, no phone, no email, and
 * a first name only. A public review list is one of the easiest places to leak
 * who ordered what and when.
 */
class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,

            /*
             * First name only, and never the phone the account signs in with.
             * "Priya" is enough to read a review as written by a person;
             * anything more identifies a customer to their neighbours.
             */
            'author' => $this->authorName(),
            'created_at' => $this->created_at,

            // Which dishes this person rated, so the app can show "they liked
            // the biryani" rather than a bare score.
            'dishes' => $this->whenLoaded('items', fn () => $this->items
                ->filter(fn ($item) => $item->menuItem !== null)
                ->map(fn ($item) => [
                    'menu_item_id' => $item->menu_item_id,
                    'name' => $item->menuItem->name,
                    'rating' => $item->rating,
                ])
                ->values()
                ->all()),
        ];
    }

    private function authorName(): string
    {
        $name = trim((string) ($this->user->name ?? ''));

        if ($name === '') {
            return __('portal.reviews.anonymous');
        }

        return explode(' ', $name)[0];
    }
}
