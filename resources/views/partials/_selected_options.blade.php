{{--
    Render a product's configurable options from an OrderConfigSnapshot
    `options` entry list. Shared by the storefront (cart / checkout /
    confirmation), the client service page, the admin order page and both
    invoice views, so one configuration reads identically everywhere.

    Each entry is shaped {id, key, group, unit, type, customer_editable,
    required, values, value_id, selected, price_unit, price_applied}. Snapshots
    written before those keys existed simply omit them and still render.

    Behaviour:
    - Entries with a `selected` value render "Group: selection", with the
      group's unit appended to a bare number ("Storage: 200 GB"). Both FIXED
      options (the value the product declares) and configurable ones arrive
      this way. Checkbox selections are arrays and are joined with ", ".
    - When `$includeUnselected` is true, entries with no selection at all fall
      back to listing their available `values` - an optional option nobody
      answered.
    - A price chip is appended from `$modifiersByLink` when the caller supplies
      one, otherwise from the entry's own `price_applied` when it is non-zero.
      Pass `$showModifiers = false` to list the configuration without prices —
      what the invoice PDF wants, since the line already carries its amount.

    @param array<int, array<string, mixed>>  $entries          snapshot entries
    @param array<int|string, float>          $modifiersByLink  link id => modifier (optional)
    @param string                            $cycle            billing cycle
    @param bool                              $includeUnselected  list values for unanswered options
    @param bool                              $showModifiers    append price chips (default true)
--}}
@php
    $rows = [];
    $cycle = $cycle ?? 'monthly';
    $modifiersByLink = $modifiersByLink ?? [];
    $showModifiers = ! isset($showModifiers) || $showModifiers;

    foreach ($entries ?? [] as $entry) {
        $selected = $entry['selected'] ?? null;
        $unit = $entry['unit'] ?? null;

        if (is_array($selected)) {
            $display = implode(', ', $selected);
        } elseif ($selected !== null && $selected !== '' && $unit) {
            // A bare number is meaningless on an invoice - "200" becomes
            // "200 GB". Labelled values already read as words and keep theirs.
            $display = is_numeric($selected) ? $selected.' '.$unit : $selected;
        } else {
            $display = $selected;
        }

        if ($display !== null && $display !== '') {
            $id = $entry['id'] ?? null;

            // The storefront prices the live catalog and passes its own map;
            // everywhere else reads what was actually charged out of the
            // snapshot. A fixed option bundled into the base price is 0 and
            // shows no chip.
            $modifier = $id !== null && array_key_exists($id, $modifiersByLink)
                ? $modifiersByLink[$id]
                : (((float) ($entry['price_applied'] ?? 0)) != 0.0 ? (float) $entry['price_applied'] : null);

            $rows[] = ['group' => $entry['group'] ?? 'Option', 'text' => $display, 'modifier' => $modifier];
        } elseif (! empty($includeUnselected) && ! empty($entry['values'])) {
            $rows[] = ['group' => $entry['group'] ?? 'Option', 'text' => implode(', ', $entry['values']), 'modifier' => null];
        }
    }
@endphp

@if ($rows !== [])
    <ul class="list-unstyled small mb-0 mt-1">
        @foreach ($rows as $row)
            <li>
                <strong>{{ $row['group'] }}:</strong> {{ $row['text'] }}
                @if ($showModifiers && $row['modifier'] !== null)
                    @include('partials._option_modifier', ['modifier' => $row['modifier'], 'cycle' => $cycle])
                @endif
            </li>
        @endforeach
    </ul>
@endif
