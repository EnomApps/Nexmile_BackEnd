<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            /*
             * Which dish this line was, as distinct from which line it is.
             *
             * Dish ratings are keyed by menu item, so without this the app has
             * nothing to attach a star to — and guessing from the line id
             * would rate whichever dish happens to share that number.
             *
             * Null when the dish has since been deleted from the menu. The
             * line still stands, because the order happened; there is just
             * nothing left to rate.
             */
            'menu_item_id' => $this->menu_item_id,

            // The snapshot, not the current menu item — a renamed dish must not
            // change what the kitchen reads off a live ticket.
            'name' => $this->name,
            'quantity' => (int) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'options_total' => (float) $this->options_total,
            'line_total' => (float) $this->line_total,
            'is_veg' => (bool) $this->is_veg,
            'notes' => $this->notes,
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn ($option) => [
                'group_name' => $option->group_name,
                'name' => $option->name,
                'price_delta' => (float) $option->price_delta,
            ])),
        ];
    }
}
