# Client Store — Full Cart Checkout

## Context

The client portal has no way to browse or purchase products. Admins can create orders manually, and there's an incomplete admin cart system, but clients have zero self-service ordering capability. This plan adds a full client-facing store with cart checkout.

## Architecture Decision

Use the **`Product`** model (not `CatalogProduct`) for the client store because:
- `Product` has pricing via `ProductPricing` relationship
- `Product` has `show_in_order` and `only_admin` flags already
- `Product` is what `Order` records reference
- `CatalogProduct` lacks pricing fields and the admin cart checkout is a stub

## Scope

### New files to create

| File | Purpose |
|------|---------|
| `app/Http/Controllers/Client/StoreController.php` | Store browsing, cart, checkout |
| `resources/views/client/store/index.blade.php` | Product catalog with category sidebar |
| `resources/views/client/store/product.blade.php` | Product detail + add-to-cart |
| `resources/views/client/store/cart.blade.php` | Cart review + remove items |
| `resources/views/client/store/checkout.blade.php` | Order summary + place order |
| `resources/views/client/store/confirmation.blade.php` | Order confirmation after placement |

### Files to modify

| File | Change |
|------|--------|
| `routes/client.php` | Add store routes |
| `config/adminlte.php` | Add "Store" link to client sidebar |
| `app/Http/Controllers/Client/DashboardController.php` | Add featured products to dashboard |
| `resources/views/client/dashboard.blade.php` | Add "Featured Products" card with top 3 products + "Browse Store" link |

### Routes (all under existing `client.` prefix + middleware)

```
GET  /client/store                   → StoreController@index         (product catalog)
GET  /client/store/{product}         → StoreController@show          (product detail)
POST /client/store/cart/add          → StoreController@addToCart     (add to session cart)
POST /client/store/cart/update       → StoreController@updateCart    (update quantity)
POST /client/store/cart/remove       → StoreController@removeFromCart
GET  /client/store/cart              → StoreController@cart          (review cart)
GET  /client/store/checkout          → StoreController@checkout      (order summary)
POST /client/store/checkout          → StoreController@placeOrder    (create order)
GET  /client/store/order/{order}     → StoreController@confirmation  (order confirmation)
```

### Controller logic

**StoreController@index:**
- Query `Product::where('show_in_order', true)->where('only_admin', false)->where('status', 'active')`
- Group by `ProductGroup` (category)
- Load `pricing` relationship for each product
- Pass to view

**StoreController@show:**
- Load product with `pricing`, `group`, `options`
- Show billing cycle selector with prices from `ProductPricing`
- Domain input if `require_domain` is true
- "Add to Cart" form

**StoreController@addToCart:**
- Validate: product_id, billing_cycle, quantity (min:1, max:99), domain (if required)
- Check if same product + billing_cycle + domain already in cart → increment quantity
- Otherwise add new item: `['product_id' => X, 'billing_cycle' => 'monthly', 'domain' => 'example.com', 'quantity' => 1]`
- Redirect back with success

**StoreController@removeFromCart:**
- Remove item by index from session cart
- Redirect back

**StoreController@cart:**
- Load cart items from session
- Resolve each to Product + pricing
- Show totals

**StoreController@checkout:**
- Show order summary (same as cart but read-only)
- Show billing info (customer name, email)
- "Place Order" button

**StoreController@placeOrder:**
- Validate cart is not empty
- For each cart item:
  1. Find Product + ProductPricing for the selected billing cycle
  2. Calculate total: `price × quantity`
  3. Create Order record (status: pending, customer_id from auth user, total)
  4. Create OrderItem record (quantity, unit_price, total, product_name)
  5. Generate order number via private `generateOrderNumber()` method
- Clear session cart
- Redirect to order confirmation page

**StoreController@confirmation:**
- Route model binding resolves `Order`
- **Authorization:** verify `$order->customer_id === auth()->user()->customer->id` → abort(403) if mismatch
- Load `items.product` relationship
- Pass to confirmation view

**StoreController (private helpers):**
- `generateOrderNumber()` — extract from admin OrderController into a shared helper or duplicate in StoreController. Format: `ORD-{YEAR}-{seq padded to 5}`

### Views

**store/index.blade.php:**
- Left sidebar: category list (like admin cart index)
- Main area: product cards in grid (name, description, price, type badge, "View" button)
- Pricing shown as "From ₹X.XX/mo" using lowest `ProductPricing` entry

**store/product.blade.php:**
- Product name, description, type badge
- Billing cycle radio buttons with prices
- Quantity input (number, min:1, max:99, default:1)
- Domain input (if required)
- Feature list (disk, bandwidth, email quotas from Product fields)
- "Add to Cart" button

**store/cart.blade.php:**
- Table: product name, billing cycle, domain, quantity (editable), unit price, line total, remove button
- Subtotal (sum of line totals)
- "Continue Shopping" + "Proceed to Checkout" buttons
- Empty cart state

**store/checkout.blade.php:**
- Order summary table (read-only): product name, billing cycle, domain, quantity, unit price, line total
- Customer info card (name, email from profile)
- Grand total
- "Place Order" button (POST form)

**store/confirmation.blade.php:**
- Success banner with order number
- Order details card (items, quantities, totals)
- "Back to Store" + "View Invoices" links

### Sidebar addition

Add to `config/adminlte.php` client section:
```php
[
    'text' => 'Store',
    'route' => 'client.store.index',
    'icon' => 'bi bi-shop',
    'role' => 'client',
],
```

### Dashboard enhancement

Add a "Featured Products" card to client dashboard showing top 3 active products with "Browse Store" link.

## Implementation order

1. StoreController (all methods)
2. Routes
3. Store views (index, product, cart, checkout, confirmation)
4. Sidebar link
5. Dashboard featured products
6. Browser verification

## Per-task QA scenarios

### Task 1: StoreController
- **Tool:** `php -l` on the controller file
- **Expected:** No syntax errors
- **Tool:** `php artisan route:list --name=client.store`
- **Expected:** All 9 store routes listed with correct methods and names

### Task 2: Routes
- **Tool:** `php artisan route:list --name=client.store`
- **Expected:** 9 routes (index, show, addToCart, updateCart, removeFromCart, cart, checkout, placeOrder, confirmation)
- **Tool:** `php artisan route:list --path=client/store`
- **Expected:** All routes under `/client/store` prefix

### Task 3: Store views
- **Tool:** Browser navigate to `http://managehosting.local/client/store` (logged in as client)
- **Expected:** Product catalog renders with categories and product cards
- **Tool:** Browser navigate to `http://managehosting.local/client/store/cart`
- **Expected:** Empty cart state renders ("Your cart is empty")
- **Tool:** Browser navigate to `http://managehosting.local/client/store/checkout`
- **Expected:** Empty cart redirect or empty state message

### Task 4: Sidebar link
- **Tool:** Browser snapshot of client dashboard
- **Expected:** "Store" link with `bi-shop` icon appears in sidebar between existing items
- **Tool:** Browser snapshot of admin dashboard
- **Expected:** No "Store" link in admin sidebar

### Task 5: Dashboard featured products
- **Tool:** Browser snapshot of client dashboard
- **Expected:** "Featured Products" card with up to 3 product entries and "Browse Store" link
- **Tool:** `php -l` on DashboardController
- **Expected:** No syntax errors

### Task 6: End-to-end flow
- **Tool:** Browser automation (Playwright)
- **Steps:**
  1. Login as client@demo.com
  2. Navigate to store, click a product
  3. Select billing cycle, set quantity to 2, add to cart
  4. Navigate to cart → verify quantity=2, line total = price × 2
  5. Update quantity to 3 → verify updated total
  6. Add another product (qty 1)
  7. Proceed to checkout → verify both items shown
  8. Place order → verify redirect to confirmation page
  9. Verify confirmation shows correct order number, items, quantities
- **Tool:** `php artisan tinker --execute="echo \App\Models\Order::where('customer_id', auth()->user()->customer->id)->latest()->first()->order_number;"`
- **Expected:** Order number matches confirmation page
- **Tool:** Session check — navigate to cart
- **Expected:** Cart is empty after order placement
