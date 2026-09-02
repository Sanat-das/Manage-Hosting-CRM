{{--
    Render the option configuration for a cart line / order item / hosting
    account from an OrderConfigSnapshot `options` entry list.

    Each entry is shaped {id, group, type, customer_editable, values, selected}
    (older backfilled snapshots may omit id/customer_editable/selected keys).

    Behaviour:
    - Entries with a non-null `selected` value render "Group: selection"
      (checkbox selections are arrays and are joined with ", ").
    - When `$includeUnselected` is true, entries without a selection render
      their full `values` list instead (informational snapshot entries).
    - `$modifiersByLink` optionally maps entry id -> summed price modifier for
      the current billing cycle; when present a modifier chip is appended.

    @param array<int, array<string, mixed>>  $entries          snapshot entries
    @param array<int|string, float>          $modifiersByLink  link id => modifier
    @param string                            $cycle            billing cycle
    @param bool                              $includeUnselected  show informational lists
--}}
@php
    $rows = [];
    $cycle = $cycle ?? 'monthly';

    foreach ($entries ?? [] as $entry) {
        $selected = $entry['selected'] ?? null;
        $display = is_array($selected) ? implode(', ', $selected) : $selected;

        if ($display !== null && $display !== '') {
            $modifier = isset($entry['id']) ? ($modifiersByLink[$entry['id']] ?? null) : null;
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
                @if ($row['modifier'] !== null)
                    @include('client.partials._option_modifier', ['modifier' => $row['modifier'], 'cycle' => $cycle])
                @endif
            </li>
        @endforeach
    </ul>
@endif
