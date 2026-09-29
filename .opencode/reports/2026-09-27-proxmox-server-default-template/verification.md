# Verification — Proxmox server edit page silently discarded the default template

- **Date:** 2026-09-27
- **Area:** Admin → Servers → `/admin/servers/{id}/edit` → Clone templates → **Default template**
- **Workflow:** bugfix
- **Reported as:** “Is this required? I am unable to set a template.” (server 6, DT-Proxmox)

## Symptom

Picking a template in **Default template** and clicking **Update Server** left the
field back at `— None —` on the next page load. No validation error, no flash.
The curated list below appeared to save normally in older setups because it came
from create-time data, but every change made on the edit page was discarded.

## Root cause

`ServerController::proxmoxConnectionMeta()` (update path) opened with a gate:

```php
$keys = ['port', 'auth_type', 'ticket_username'];
// … return null unless one of those (or verify_tls) is present in $validated
```

Those are exactly the keys the Proxmox **create** page renders from
`Proxmox::serverConfigSchema()` (port / auth_type / verify_tls). The **edit**
page renders none of them — verified in the live DOM:
`select[name="auth_type"]`, `input[name="port"]`, `input[name="verify_tls"]`
→ “No element matches”. So on every save from `/admin/servers/{id}/edit`,
`$proxmoxMeta` returned `null`, `$attributes['connection_meta']` was never set,
and the curation payload (`proxmox_templates_selected[]` + `proxmox_template_default`)
was silently dropped by `Server::update()`.

Live DB before the fix: server 6 had 4 curated VMIDs (103/109/110/113) and
`proxmox_template_default = null`.

## Failing test first

`ProxmoxTemplateCatalogTest::test_the_edit_form_saves_the_default_template_without_transport_fields`
— a PUT with the exact field set the edit form posts (curation only, no
transport keys):

```
Failed asserting that null is identical to '110'.
```

## Fix (minimal)

1. `app/Http/Controllers/Admin/ServerController.php` — the gate now also counts
   `proxmox_templates` / `proxmox_template_default` as “this request touches
   Proxmox meta”, so a curation-only save runs the merge. Transport prefs are
   still preserved from `$existing`.
2. `resources/views/admin/servers/edit.blade.php` — the Default template picker
   (Proxmox **and** Virtualizor) now offers only the ticked/curated rows. It
   previously listed every discovered template while `proxmoxConnectionMeta()`
   drops a default outside the curated list, so an unchecked pick vanished on
   save with no error.

## Verification

| Check | Command / action | Result |
| --- | --- | --- |
| Regression test (before) | `php artisan test --filter=test_the_edit_form_saves_the_default_template_without_transport_fields` | 1 failed — default was `null` |
| Regression test (after) | same | 1 passed |
| Picker render test (new) | `test_the_default_template_picker_only_offers_curated_templates` | 1 passed |
| Proxmox catalog suite | `php artisan test --filter=ProxmoxTemplateCatalogTest` | 26 passed (86 assertions) |
| Server/PVE/VZ suites | `ServerEditTest`, `ServerTestConnectionMetaTest`, `ServerHypervTemplateTest`, `ServerCreateFlowTest`, `ServerEssentialTransportTest`, `VirtualizorVmLifecycleTest`, `ProductHypervTemplateRestrictionTest` | 96 passed (463 assertions) |
| Full suite | `php artisan test` | 2575 passed (12621 assertions) |
| Live DOM pre-check | browser inspect of the edit page | no transport inputs exist in the form; select + 4 curated rows present |

Formatting note: `vendor/bin/pint --dirty` was run and then **reverted** for
`ServerController.php` — the file has pre-existing Pint violations, so running it
created a 126-line unrelated reformat. The two changed hunks follow the
surrounding style; CI does not run Pint.

## Blast radius

- Proxmox servers: edits from `/admin/servers/{id}/edit` now persist curation,
  labels and the default template; transport/auth prefs saved earlier survive
  (asserted in the regression test).
- Virtualizor: only the default picker option list changed; its save gate
  already keyed on the curation fields.
- No route, schema, model or migration changes.
