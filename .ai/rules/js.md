---
paths:
  - 'resources/views/**/*.blade.php, resources/js/*.js'
---

# Js

## Bootstrap JS is not a global — use the data-api
The Vite bundle imports Bootstrap as ESM (side-effect only) and sets no window.bootstrap. Calling bootstrap.Modal / bootstrap.Dropdown throws ReferenceError at runtime. To open a modal or close a dropdown from inline JS, drive Bootstrap's data-api instead: triggerEl.click() on an element carrying data-bs-toggle="modal" data-bs-target="#id", or click a [data-bs-dismiss="modal"] button. See admin/settings/index.blade.php:2624-2626 for the in-repo note, and resources/views/admin/products/edit.blade.php for a working example.
