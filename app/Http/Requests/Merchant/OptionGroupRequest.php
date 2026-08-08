<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class OptionGroupRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'selection' => ['sometimes', 'in:single,multiple'],
            'is_required' => ['sometimes', 'boolean'],
            'min_selections' => ['sometimes', 'integer', 'between:0,50'],
            'max_selections' => ['sometimes', 'nullable', 'integer', 'between:1,50'],
            'sort_order' => ['sometimes', 'integer', 'between:0,9999'],

            /*
             * Options are written with their group. A group with no choices is
             * a dead end at checkout, so creating them separately would leave
             * a window where the menu is unorderable.
             */
            'options' => [$required, 'array', 'min:1', 'max:50'],
            'options.*.id' => ['sometimes', 'nullable', 'integer'],
            'options.*.name' => ['required', 'string', 'max:255'],
            'options.*.price_delta' => ['sometimes', 'numeric', 'between:-9999,9999'],
            'options.*.is_available' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Cross-field rules. Each of these describes a group a customer could
     * never satisfy, so checkout would reject every order containing the item
     * — with the merchant having no idea why.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $selection = $this->input('selection', 'single');
            $min = (int) $this->input('min_selections', 0);
            $max = $this->input('max_selections');
            $count = is_array($this->input('options')) ? count($this->input('options')) : 0;
            $required = $this->boolean('is_required');

            if ($max !== null && $min > (int) $max) {
                $validator->errors()->add('min_selections', 'The minimum cannot be more than the maximum.');
            }

            if ($count > 0 && $min > $count) {
                $validator->errors()->add('min_selections', "This group has {$count} choices, so the minimum cannot be higher.");
            }

            if ($selection === 'single' && $max !== null && (int) $max > 1) {
                $validator->errors()->add('max_selections', 'A single-choice group allows at most one selection.');
            }

            // "Required" and "pick none" contradict each other.
            if ($required && $min < 1) {
                $validator->errors()->add('min_selections', 'A required group needs a minimum of at least 1.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        /*
         * The portal renders a fixed number of blank option rows so a merchant
         * can add several at once. Empty ones are not input, so they are
         * dropped before validation rather than failing as unnamed options.
         */
        if (is_array($options = $this->input('options'))) {
            $this->merge([
                'options' => array_values(array_filter(
                    $options,
                    fn ($option) => filled($option['name'] ?? null),
                )),
            ]);
        }

        // The portal posts a single "required" checkbox; a required group
        // always means at least one choice, so infer rather than making the
        // merchant reason about two fields that must agree.
        if ($this->boolean('is_required') && ! $this->has('min_selections')) {
            $this->merge(['min_selections' => 1]);
        }

        if ($this->input('max_selections') === '') {
            $this->merge(['max_selections' => null]);
        }
    }
}
