<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * The invoice form's contract.
 *
 * Store and update live in one request so their rules cannot drift apart —
 * they had, and the drift is how a create form could post an "amount" the
 * server ignored, while an edit form posted a customer the server refused.
 *
 * Three rules are worth naming:
 *
 *  - the amount is derived from the line items, never posted;
 *  - a status that means "the money moved" (paid, partial, refunded) is not
 *    settable here — those belong to the payment and refund actions;
 *  - a form opened before the invoice changed is stale, and says so instead
 *    of failing every line id it posts.
 */
class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The policy is the authority; the route middleware is the coarse gate.
        return true;
    }

    public function rules(): array
    {
        $invoice = $this->route('invoice');

        if ($invoice instanceof Invoice) {
            return $this->updateRules($invoice);
        }

        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'in:draft,sent'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function updateRules(Invoice $invoice): array
    {
        // Amounts are frozen once money moved (payments recorded or a
        // settled/terminal status): only status/due_date/notes remain
        // editable.
        if ($invoice->isAmountLocked()) {
            return [
                'status' => ['sometimes', 'string', Rule::in(array_keys($invoice->editableStatusTransitions()))],
                'due_date' => ['nullable', 'date'],
                'notes' => ['nullable', 'string', 'max:1000'],
                'updated_at' => ['nullable', 'date'],
            ];
        }

        return [
            // The customer is NOT reassignable here: an invoice belongs to
            // the customer it was raised for. A posted customer_id is ignored.
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['required', 'string', Rule::in(array_keys($invoice->editableStatusTransitions()))],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'updated_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            // Existing lines identify themselves so the save can restore what
            // the form cannot post back: the product link (which also drives
            // per-product GST) and the options the line bills for. Absent on a
            // line the admin just added. Scoped to THIS invoice — the id comes
            // from the browser, and one belonging to another invoice must not
            // be able to pull that invoice's product and configuration here.
            'items.*.id' => [
                'nullable',
                'integer',
                // One stored line cannot be two submitted lines. Without this,
                // a duplicated row would hand its product — and therefore its
                // per-product GST treatment — to a line that is not it.
                'distinct',
                Rule::exists('invoice_items', 'id')->where('invoice_id', $invoice->id),
            ],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * A form opened before the invoice changed is stale.
     *
     * The line ids it posts are gone by then, so every one of them would
     * fail validation with a message about ids the admin never saw. Say what
     * actually happened instead, and stop before anything is written.
     */
    protected function prepareForValidation(): void
    {
        $posted = $this->input('updated_at');
        $invoice = $this->route('invoice');

        if ($posted === null || $posted === '' || ! $invoice instanceof Invoice) {
            return;
        }

        $current = $invoice->updated_at?->toDateTimeString();

        if ($current === null || Carbon::parse($posted)->toDateTimeString() === $current) {
            return;
        }

        throw ValidationException::withMessages([
            'updated_at' => 'This invoice was changed by someone else while you had it open. Reload the page and try again.',
        ]);
    }

    /**
     * The amount is derived from the lines, so the discount ceiling can only
     * be checked once the lines are known.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('items')) {
                return;
            }

            $amount = 0.0;

            foreach ((array) $this->input('items', []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $amount += (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0);
            }

            $amount = round($amount, 2);
            $discount = (float) $this->input('discount', 0);

            if ($discount > $amount) {
                $validator->errors()->add(
                    'discount',
                    'The discount cannot be larger than the invoice amount ('.number_format($amount, 2).').'
                );
            }
        });
    }
}
