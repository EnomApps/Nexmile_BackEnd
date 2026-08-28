<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /*
         * Keyed on the route parameter, not the HTTP verb. The API updates
         * over POST because PHP does not parse multipart bodies on PATCH, so
         * the verb cannot tell create from update.
         */
        $required = $this->route('item') === null ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],

            /*
             * Scoped to this merchant. Without the where clause a merchant
             * could file a dish under a competitor's category id, which the
             * customer menu would then render in the wrong storefront.
             */
            'category_id' => [
                'sometimes', 'nullable',
                Rule::exists('categories', 'id')
                    ->where('merchant_id', $this->user()->merchant?->id)
                    ->whereNull('deleted_at'),
            ],

            'price' => [$required, 'numeric', 'between:1,99999.99'],

            /*
             * The struck-through "was" price. Below the actual price it would
             * advertise a discount that does not exist.
             */
            'compare_at_price' => ['sometimes', 'nullable', 'numeric', 'between:1,99999.99', 'gt:price'],

            'gst_rate' => ['sometimes', Rule::in(config('menu.gst_rates'))],

            /*
             * A pure vegetarian kitchen cannot list a non-veg dish.
             *
             * Nothing stopped this before, so a shop could tick "pure veg" and
             * still sell mutton biryani. A customer filtering veg-only would
             * be sent there, open the menu, and stop trusting the filter —
             * which costs more than the filter was ever worth.
             */
            'is_veg' => [
                'sometimes', 'boolean',
                function (string $attribute, $value, \Closure $fail) {
                    if (! $this->toBoolean($value) && $this->user()->merchant?->is_pure_veg) {
                        $fail(__('portal.menu.veg_conflict_dish'));
                    }
                },
            ],
            'contains_egg' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],

            'prep_time_minutes' => ['sometimes', 'integer', 'between:1,120'],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],

            'image' => [
                'sometimes', 'nullable', 'file',
                'mimes:'.implode(',', config('media.mimes')),
                'max:'.config('media.max_size_kb'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $mb = round(config('media.max_size_kb') / 1024, 1);

        return [
            'category_id.exists' => 'That category does not belong to your menu.',
            'compare_at_price.gt' => 'The original price must be higher than the current price.',
            'gst_rate.in' => 'GST must be one of '.implode('%, ', array_map('intval', config('menu.gst_rates'))).'%.',
            'image.mimes' => 'Upload a JPG, PNG or WebP photo.',
            'image.max' => "Photos must be under {$mb} MB.",
            // PHP discards an oversized upload before the max rule can run.
            'image.uploaded' => "That photo is too large to upload. Keep it under {$mb} MB.",
        ];
    }

    protected function prepareForValidation(): void
    {
        /*
         * An empty select posts "" from the portal form, which would fail the
         * exists rule. Treat it as "no category" — the item shows under
         * Uncategorised.
         */
        if ($this->input('category_id') === '') {
            $this->merge(['category_id' => null]);
        }

        if ($this->input('compare_at_price') === '') {
            $this->merge(['compare_at_price' => null]);
        }
    }

    /** The portal posts "0"/"1" strings; the API sends real booleans. */
    private function toBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
