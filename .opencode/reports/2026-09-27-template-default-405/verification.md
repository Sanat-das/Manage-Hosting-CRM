# Verification — product default-template save (the "405" that saved anyway)

- **Date:** 2026-09-27
- **Area:** Admin → Products → Modules tab → “<Module> default template” card
- **Workflow:** bugfix
- **Commits:** `ae148c03` (superseded workaround), this change (JSON answer)

## Symptom

Clicking **Save default** on the card showed *“Could not save the default
template.”* and later, with the status surfaced, **HTTP 405** — while the value
was in fact being written.

## Root cause

The card saves over `fetch()`. The endpoint answered with a **302 redirect**
back to the product edit page. Fetch follows 301/302 with the **same method**
(only `POST` is rewritten to `GET`), so the follow-up `PUT` hit the GET-only
edit route:

```
PUT /admin/products/2/modules/proxmox/template-default   → 419  (reaches Laravel; CSRF)
PUT /admin/products/2/edit                               → 405  (GET-only route)
POST /admin/products/2/edit                              → 405
GET  /admin/products/2/edit                              → 302
```

The 405 was Laravel’s `MethodNotAllowedHttpException` on the edit route — not
the web host. nginx 1.26.1 passes `PUT` (the 419 proves the request reached the
CSRF middleware). The earlier “IIS/WebDAV rejects PUT” explanation was wrong and
has been corrected in the CHANGELOG and code.

## Reproduction

1. Browser: `/admin/products/2/edit?tab=modules` → click **Save default**.
2. Error box: `Could not save the default template (HTTP 405).`
3. `curl -X PUT …/template-default` → 419; `curl -X PUT …/edit` → 405.

## Failing test first

`ProductComputeTemplateDefaultTest::test_endpoint_answers_ajax_callers_with_json_not_a_redirect`

```
Expected response status code [200] but received 302.
```

## Fix (minimal)

`ProductModuleController::updateTemplateDefault()` answers AJAX callers
(`Accept: application/json`, which the card already sends) with
`{"ok":true,"template":…,"message":…}` instead of a redirect. Non-AJAX callers
keep the redirect + flash. The card keeps its plain `PUT` fetch; its error
message still includes the HTTP status for real failures. The earlier
method-spoofing workaround was reverted.

## Verification

| Check | Command / action | Result |
| --- | --- | --- |
| Regression test (before) | `php artisan test --filter=test_endpoint_answers_ajax` | 1 failed — 302, expected 200 |
| Regression test (after) | same | 1 passed |
| Default-template suite | `php artisan test tests/Feature/ProductComputeTemplateDefaultTest.php` | 8 passed (39 assertions) |
| Mode suite | `php artisan test tests/Feature/ProductProvisioningModeTest.php` | 11 passed (42 assertions) |
| Formatting | `vendor/bin/pint --dirty` | fixed `ProductModuleController` |
| Browser re-check | open card (select shows 110) → force DB to 103 behind it → click **Save default** | DB became 110, no error box |
| Full suite | `php artisan test` | pending — filled in when the background run completes |

## Blast radius

- Only the product default-template endpoint and card.
- Non-AJAX save behaviour (redirect + flash) is unchanged and covered by the
  existing tests.
