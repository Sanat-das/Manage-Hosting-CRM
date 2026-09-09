{{--
    Shared client behaviour for the option-group create / edit forms.

    Two jobs:

    1. Progressive disclosure. Every input type uses a different subset of the
       fields, and showing all of them at once is what made this form
       unreadable — a dropdown has no Min/Max/Step, and a slider has no value
       list (it is priced per unit on the product, not per value).

       Hiding is COSMETIC ONLY: a hidden block still posts its current values,
       so flipping the type to look at something and saving can never silently
       wipe a value list or a Min/Max an admin still wants. The one field that
       appears twice — input_max, as a range bound and as a checkbox's maximum
       selections — is disambiguated server-side by type, not by disabling an
       input here.

    2. The value list: add / remove / reorder rows, renumbering sort_order from
       the row order so the admin never types an ordering by hand, and keeping
       exactly one Default radio ticked.
--}}
<script>
    (function () {
        const FIELDS_BY_TYPE = {
            dropdown: ['values', 'unit'],
            radio: ['values', 'unit'],
            checkbox: ['values', 'unit', 'max-selections'],
            slider: ['unit', 'range', 'placeholder', 'unit-price-note'],
            number: ['unit', 'range', 'placeholder', 'unit-price-note'],
            quantity: ['unit', 'range', 'placeholder', 'unit-price-note'],
            text: ['placeholder', 'text-note'],
        };

        const typeSelect = document.getElementById('option-type-select');
        const blocks = Array.from(document.querySelectorAll('[data-option-field]'));

        function applyType() {
            const visible = FIELDS_BY_TYPE[typeSelect ? typeSelect.value : 'dropdown'] || [];

            blocks.forEach(function (block) {
                block.classList.toggle('d-none', visible.indexOf(block.dataset.optionField) === -1);
            });
        }

        if (typeSelect) {
            typeSelect.addEventListener('change', applyType);
        }

        const container = document.getElementById('option-values-container');
        const template = document.getElementById('option-value-template');
        const addBtn = document.getElementById('add-option-value');

        function rows() {
            return Array.from(container.querySelectorAll('.option-value-row'));
        }

        // Display order is the row order — renumber after every mutation.
        function renumber() {
            rows().forEach(function (row, position) {
                const sortOrder = row.querySelector('.value-sort-order');
                if (sortOrder) sortOrder.value = position;
            });

            const radios = rows().map(function (row) {
                return row.querySelector('.default-value-radio');
            }).filter(Boolean);

            // A list with no default would silently fall back to its first
            // row; make that visible instead.
            if (radios.length && !radios.some(function (radio) { return radio.checked; })) {
                radios[0].checked = true;
            }
        }

        if (container && template && addBtn) {
            let index = {{ $nextIndex }};

            addBtn.addEventListener('click', function () {
                container.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__index__', index));
                index++;
                renumber();
            });

            container.addEventListener('click', function (e) {
                const row = e.target.closest('.option-value-row');
                if (!row) return;

                if (e.target.closest('.remove-option-value')) {
                    row.remove();
                    renumber();
                    return;
                }

                if (e.target.closest('.move-value-up') && row.previousElementSibling) {
                    row.parentNode.insertBefore(row, row.previousElementSibling);
                    renumber();
                    return;
                }

                if (e.target.closest('.move-value-down') && row.nextElementSibling) {
                    row.parentNode.insertBefore(row.nextElementSibling, row);
                    renumber();
                }
            });

            renumber();
        }

        applyType();
    })();
</script>
