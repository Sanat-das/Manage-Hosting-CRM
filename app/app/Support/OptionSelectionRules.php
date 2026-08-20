<?php

namespace App\Support;

use App\Models\ProductOptionGroupProduct;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Per-type validation rules for configurable-option selections.
 *
 * Single source of truth shared by the client storefront (StoreController),
 * the admin order form (OrderRequest) and the admin cart. Each customer-
 * editable option link contributes one rule set keyed by its link id under
 * the given prefix, matching the input type of its option group:
 *
 *  - checkbox                 required array (max = link value count)
 *  - quantity / number        required integer within the link's min/max
 *  - slider                   required integer within the link's min/max
 *  - text                     required string (max 255)
 *  - dropdown / radio (etc.)  required value from the link's value labels
 *
 * @param  Collection<int, ProductOptionGroupProduct>  $links  option links (with group + linkValues loaded)
 * @param  string  $prefix  dotted key prefix, e.g. 'lines.0.options' or 'options'
 * @return array<string, list<mixed>>
 */
class OptionSelectionRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function forLinks(Collection $links, string $prefix = 'options'): array
    {
        $rules = [];

        foreach ($links as $link) {
            $key = $prefix.'.'.$link->id;
            $type = $link->group?->type ?? 'dropdown';

            switch ($type) {
                case 'checkbox':
                    // Selections cap: the group's input_max when set (e.g.
                    // "pick up to 3"), else every value (guards against
                    // duplicated-label payloads).
                    $maxCheckboxes = (int) ($link->input_max ?? $link->group?->input_max ?? $link->linkValues->count());
                    $rules[$key] = ['required', 'array', 'max:'.max(1, $maxCheckboxes)];
                    $rules[$key.'.*'] = ['string'];
                    break;

                case 'quantity':
                    // Quantity is a count — whole units only.
                    $rules[$key] = ['required', 'integer', 'min:'.self::inputMin($link)];
                    if (self::inputMax($link) !== null) {
                        $rules[$key][] = 'max:'.self::inputMax($link);
                    }
                    break;

                case 'number':
                    // Decimal values allowed (step-driven, e.g. 1.5 TB) — the
                    // value must also be on the option's step grid, matching
                    // the native step attribute on the storefront control.
                    $rules[$key] = ['required', 'numeric', 'min:'.self::inputMin($link), 'max:'.(self::inputMax($link) ?? PHP_FLOAT_MAX), self::stepRule($link)];
                    break;

                case 'text':
                    $rules[$key] = ['required', 'string', 'max:255'];
                    break;

                case 'slider':
                    // Decimal steps allowed (e.g. 0.5-core increments) — value
                    // must land on the slider's step grid.
                    $rules[$key] = ['required', 'numeric', 'min:'.self::inputMin($link), 'max:'.(self::inputMax($link) ?? 100), self::stepRule($link)];
                    break;

                case 'dropdown':
                case 'radio':
                default:
                    $rules[$key] = ['required', Rule::in($link->linkValues->pluck('label')->all())];
                    break;
            }
        }

        return $rules;
    }

    /**
     * The min bound for a continuous link's numeric control: the product's
     * per-link override wins, else the catalog group's value, else 0.
     * Decimals are preserved (step-driven controls may start at 0.5).
     */
    public static function inputMin(ProductOptionGroupProduct $link): int|float
    {
        $min = $link->input_min ?? $link->group?->input_min;

        return $min !== null ? (float) $min : 0;
    }

    /**
     * The max bound for a continuous link's numeric control: the product's
     * per-link override wins, else the catalog group's value, else null.
     * Decimals are preserved.
     */
    public static function inputMax(ProductOptionGroupProduct $link): ?float
    {
        $max = $link->input_max ?? $link->group?->input_max;

        return $max !== null ? (float) $max : null;
    }

    /**
     * A closure rule enforcing the option's step grid, mirroring the native
     * `step` attribute on the storefront controls: (value - min) must be an
     * integer multiple of the step. Float tolerance keeps values like 2.5 on
     * a 0.5 grid while rejecting genuinely out-of-step values like 2.4.
     */
    public static function stepRule(ProductOptionGroupProduct $link): \Closure
    {
        $min = self::inputMin($link);
        $step = (float) ($link->input_step ?? $link->group?->input_step ?? 1);

        return function (string $attribute, mixed $value, $fail) use ($min, $step): void {
            if ($step <= 0) {
                return;
            }

            $offset = ((float) $value - $min) / $step;

            if (abs($offset - round($offset)) > 1e-6) {
                $fail('The '.str_replace('_', ' ', $attribute).' must be in increments of '.rtrim(rtrim(number_format($step, 4, '.', ''), '0'), '.').'.');
            }
        };
    }
}
