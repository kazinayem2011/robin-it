<?php

namespace App\Http\Requests\Admin;

use App\Models\Attribute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A filter question and the answers it allows.
 *
 * The values arrive whole and replace what is stored, the way specifications
 * do on a product: a value the admin deleted is one that is simply absent from
 * the list. The controller is what refuses to drop one that products are using.
 */
class AttributeRequest extends FormRequest
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
        return [
            /*
             * Not unique. Six shelves ask "Features" and four ask "Type", each
             * about its own kind of product, and they are genuinely different
             * questions with different answers. The slug carries the uniqueness
             * instead, and is generated rather than typed.
             */
            'name' => 'required|string|max:120',

            'input_type' => ['required', Rule::in(Attribute::INPUT_TYPES)],

            /*
             * Shown after the number: Mbps, inch, Hz. Meaningless on a list of
             * names, so it is cleared rather than kept when the type is not a
             * number — a stale "Mbps" on a panel-type question is the kind of
             * thing nobody notices until a customer does.
             */
            'unit' => 'nullable|string|max:24',

            'sort_order' => 'nullable|integer|min:0|max:10000',

            /*
             * Which shelves ask it. Attached to the product-type category and
             * inherited by everything under it, so a router question is
             * declared once rather than under every router brand.
             */
            'category_ids' => 'nullable|array|max:50',
            'category_ids.*' => 'integer|exists:categories,id',

            'values' => 'required|array|min:1|max:60',
            'values.*.id' => 'nullable|integer',
            'values.*.label' => 'required|string|max:120',
            'values.*.sort_order' => 'nullable|integer|min:0|max:10000',

            /*
             * The bounds of a band, for a numeric question. Held on the value
             * so "751 Mbps to 1200 Mbps" stays one row a shopper ticks while
             * the numbers remain available for placing a new product.
             */
            'values.*.range_from' => 'nullable|numeric',
            // The ordering of the two is checked row by row below: a wildcard
            // in a rule's *parameter* is not substituted, so `gte:values.*.
            // range_from` compares against a field that does not exist and
            // refuses every open-ended band — "Up to 300 Mbps" among them.
            'values.*.range_to' => 'nullable|numeric',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'values.required' => 'A filter needs at least one answer, or there is nothing to tick.',
            'values.min' => 'A filter needs at least one answer, or there is nothing to tick.',
            'values.*.label.required' => 'Every answer needs a label.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $values = $this->input('values', []);

            if (! is_array($values)) {
                return;
            }

            $this->checkLabelsAreDistinct($validator, $values);
            $this->checkBandsSuitTheType($validator, $values);
        });
    }

    /**
     * Two answers with the same label are one checkbox drawn twice: the shopper
     * ticks one and the other still hides half the matching products.
     *
     * @param  array<int, mixed>  $values
     */
    private function checkLabelsAreDistinct(Validator $validator, array $values): void
    {
        $seen = [];

        foreach ($values as $i => $value) {
            $label = mb_strtolower(trim((string) ($value['label'] ?? '')));

            if ($label === '') {
                continue;
            }

            if (isset($seen[$label])) {
                $validator->errors()->add(
                    "values.{$i}.label",
                    'This answer is already on the list above.'
                );

                continue;
            }

            $seen[$label] = true;
        }
    }

    /**
     * A number question whose bands carry no bounds cannot place a product, and
     * a bound on a list of names means nothing at all.
     *
     * @param  array<int, mixed>  $values
     */
    private function checkBandsSuitTheType(Validator $validator, array $values): void
    {
        $isNumber = $this->input('input_type') === Attribute::NUMBER;

        foreach ($values as $i => $value) {
            $from = $value['range_from'] ?? null;
            $to = $value['range_to'] ?? null;
            $hasBounds = $from !== null && $from !== '' || $to !== null && $to !== '';

            if ($isNumber && ! $hasBounds) {
                $validator->errors()->add(
                    "values.{$i}.range_from",
                    'A band needs a lower or an upper bound, otherwise nothing falls into it. '
                        .'Leave one end open for "Up to 300" or "1801 and above".'
                );

                continue;
            }

            if (! $isNumber && $hasBounds) {
                $validator->errors()->add(
                    "values.{$i}.range_from",
                    'Only a number filter has bands. Switch the type, or clear the bounds.'
                );

                continue;
            }

            // Only when the band is closed at both ends. One open end is the
            // normal shape of the first and last rows on a shop's filter list.
            if ($from !== null && $from !== '' && $to !== null && $to !== ''
                && (float) $to < (float) $from) {
                $validator->errors()->add(
                    "values.{$i}.range_to",
                    'A band cannot end before it starts.'
                );
            }
        }
    }
}
