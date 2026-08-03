<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemOptionGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'selection' => $this->selection,
            'is_required' => (bool) $this->is_required,
            'min_selections' => (int) $this->min_selections,
            'max_selections' => $this->max_selections !== null ? (int) $this->max_selections : null,
            'sort_order' => (int) $this->sort_order,
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn ($option) => [
                'id' => $option->id,
                'name' => $option->name,
                'price_delta' => (float) $option->price_delta,
                'is_available' => (bool) $option->is_available,
                'sort_order' => (int) $option->sort_order,
            ])),
        ];
    }
}
