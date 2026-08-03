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
