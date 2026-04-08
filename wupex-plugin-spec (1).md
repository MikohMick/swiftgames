# Wupex Gift Card Plugin — Claude Code Spec

## Project Summary

A WordPress plugin for **Swift Overtime Enterprise** that connects WooCommerce to the Wupex API.
It automates PSN (and other gift card) delivery — when a customer pays, they receive an email
with a secure link to reveal their unique PIN code. No manual fulfilment required.

---

## Plugin Details

- **Plugin Name:** Wupex Gift Cards
- **Folder:** `wupex-gift-cards/`
- **Main File:** `wupex-gift-cards.php`
- **Text Domain:** `wupex-gift-cards`
- **Requires:** WordPress 6.0+, WooCommerce 7.0+, PHP 8.0+

---

## Settings Page

Create an admin settings page under **WooCommerce → Wupex Gift Cards**.

### Settings Fields

| Field | Key | Type | Description |
|-------|-----|------|-------------|
| API Key | `wupex_api_key` | password | x-api-key header value |
| Merchant Code | `wupex_merchant_code` | text | Merchant code sent in order body |
| API Environment | `wupex_environment` | select | Sandbox / Production |
| Encryption Key | `wupex_encryption_key` | password | Used to encrypt PIN codes in DB |
| Reveal Link Expiry | `wupex_token_expiry_hours` | number | Hours before reveal token expires (default: 72) |
| Price Markup | `wupex_markup_percentage` | number | Percentage markup added on top of Wupex price on import (e.g. 20 = 20%). Applied automatically to all imported products |
| Auto-create Categories | `wupex_auto_categories` | checkbox | Automatically create WooCommerce categories from `productType` and assign products on import (default: enabled) |

### API URL Logic

Do not expose sandbox/production URLs as editable fields. Build them dynamically:

- When environment = **Sandbox**: base URL = `https://sandbox-service.wupex.com`
- When environment = **Production**: base URL = `https://service.wupex.com`
- Append endpoint path to whichever base URL is active
- Example: `{base_url}/api/product/merchant/invited/list`

The merchant only toggles one dropdown — no manual URL editing needed.

### Markup Calculation

When importing products, apply markup before setting WooCommerce price:

```
woocommerce_price = wupex_price + (wupex_price * markup_percentage / 100)
```

Example: Wupex price = $9.50, markup = 20% → WooCommerce price = $11.40. Round to 2 decimal places.

### Settings Page Actions

- **Test Connection** button — calls `GET /api/customer/balance` and shows live balance + credit
- **Save Settings** button — standard WordPress options save

---

## Database Tables

Create these custom tables on plugin activation.

### Table 1: `{prefix}wupex_orders`

Stores the link between a WooCommerce order and a Wupex order.

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT AUTO_INCREMENT PRIMARY KEY | |
| wc_order_id | BIGINT | WooCommerce order ID |
| wupex_order_name | VARCHAR(100) | e.g. P0000000005202 |
| wupex_request_id | VARCHAR(100) | requestId from Wupex response |
| wc_product_id | BIGINT | WooCommerce product ID |
| sku | VARCHAR(100) | Wupex product SKU |
| quantity | INT | Number of codes ordered |
| total_amount | DECIMAL(10,4) | Amount charged by Wupex |
| status | VARCHAR(50) | pending / fulfilled / failed |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### Table 2: `{prefix}wupex_codes`

Stores individual PIN codes, one row per code.

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT AUTO_INCREMENT PRIMARY KEY | |
| wc_order_id | BIGINT | WooCommerce order ID |
| wupex_order_name | VARCHAR(100) | From Wupex |
| wc_product_id | BIGINT | WooCommerce product ID |
| product_name | VARCHAR(255) | Product name from Wupex |
| sku | VARCHAR(100) | Wupex SKU |
| serial_number | VARCHAR(255) | serialNumber from Wupex |
| serial_code_encrypted | TEXT | serialCode — AES encrypted |
| expiry | VARCHAR(100) | Expiry date if provided, else null |
| reveal_token | VARCHAR(100) | Unique random token for reveal link |
| token_expiry | DATETIME | When the reveal token expires |
| revealed_at | DATETIME | When customer first revealed the code |
| reveal_count | INT DEFAULT 0 | How many times code has been revealed |
| status | VARCHAR(50) | unrevealed / revealed / refunded |
| created_at | DATETIME | |

---

## Core Flow

### Trigger: WooCommerce Order Complete

Hook: `woocommerce_order_status_completed`

**Only fire this hook when:**
- Order status changes TO `completed`
- Payment is confirmed (not just status manually changed — check `$order->is_paid()`)
- Do NOT fire for: on-hold, pending, cancelled, failed, refunded

**Steps when triggered:**

1. Loop through each order item in the WooCommerce order
2. Check if the product has a `_wupex_sku` meta field — if not, skip it (not a Wupex product)
3. Get the SKU and quantity for that item
4. Call Wupex `POST /api/order/pull-codes` with:
   - `merchant` — from settings
   - `sku` — from product meta
   - `quantity` — from order item
   - Query param `referenceId` — format: `WC-{order_id}-{item_id}-{timestamp}`
   - Query param `allowTakeAll=true` — always
5. Store the `orderName` and `requestId` in `wupex_orders` table
6. Immediately call `GET /api/order/detail?orderName={orderName}`
7. Loop through `orderData[].serials[]` — one row per serial
8. For each serial:
   - Encrypt `serialCode` using AES-256 with the encryption key from settings
   - Generate a unique random `reveal_token` (32 char hex)
   - Set `token_expiry` = now + hours from settings
   - Insert row into `wupex_codes` table
9. Send customer the **code ready email** (see Email section below)
10. If Wupex API call fails — log the error, add an order note in WooCommerce, do NOT mark order as failed (payment already taken). Flag for manual review.

---

## Product Import Feature

Create a sub-page: **WooCommerce → Wupex Gift Cards → Import Products**

### Import Flow

1. Call `POST /api/product/merchant/invited/list` paginating through ALL pages
2. **Filter: only show products where `available > 0`** (in stock only — no point importing unavailable products)
3. **Limit display to 50 products** for initial testing — show a notice: "Showing 50 in-stock products. Increase limit once ready for full catalog."
4. Display fetched products in a table showing:
   - Product image (thumbnail from `imageUrl`)
   - Product name
   - SKU (`productCode`)
   - Wupex price
   - Calculated WooCommerce price (after markup from settings)
   - Available stock
   - Product type / category
   - Status: Already imported / Not imported
5. Allow merchant to:
   - Select individual products or **Select All**
   - Click **Import Selected**
6. On import, for each selected product:
   - Create a WooCommerce Simple Product
   - Set product name from `productName`
   - Calculate and set regular price: `wupex_price + (wupex_price * markup_percentage / 100)` rounded to 2dp
   - Set SKU to `productCode` (WooCommerce SKU field)
   - Save `_wupex_sku` meta = `productCode` (used by order hook)
   - Save `_wupex_price` meta = original Wupex price (for reference)
   - Set stock quantity from `available` (manage stock = true)
   - Set stock status: in stock if `available > 0`, else out of stock
   - Download and attach product image from `imageUrl` if not empty
   - Set virtual = true, downloadable = false
   - **Auto-create categories** (if setting enabled):
     - Use `productType` as the category name (e.g. "Apple iTunes", "Google Play", "PSN")
     - Check if category already exists before creating — never duplicate
     - Assign product to that category automatically
7. Show import progress bar and result summary (X imported, X skipped, X failed)

### Re-sync Stock Button

- A **Sync Stock** button that re-calls the products API and updates `available`
  stock quantities for all already-imported Wupex products without changing prices or details
- Also run automatically via a daily WP-Cron job at midnight
- Log each sync run to the Wupex log file

---

## Emails

### Email 1: Code Ready Notification

- **Trigger:** After codes are successfully fetched from Wupex
- **To:** Customer email from WooCommerce order
- **Subject:** Your PSN Gift Card is Ready — Order #{order_id}
- **Content:**
  - Thank them for their order
  - Tell them their gift card code is ready
  - Show a button: **Reveal My Key**
  - Button links to the reveal page with their token
  - Do NOT include the actual code in the email
- **Style:** Match WooCommerce transactional email style

### Email 2: Thank You Page Message

- On the WooCommerce **order received / thank you page** show a notice:
  > "Thank you for your order! Your gift card code is being prepared.
  >  You will receive an email shortly with instructions to reveal your code."
- Hook: `woocommerce_thankyou`

---

## Reveal Page

### Setup

- Register a custom WooCommerce endpoint: `my-account/reveal-code`
- Or create a shortcode `[wupex_reveal_code]` that can be placed on any page

### Reveal Flow

1. Customer clicks the link from their email
2. URL format: `https://yoursite.com/reveal-code/?token={reveal_token}`
3. Plugin looks up the token in `wupex_codes` table
4. Validate:
   - Token exists
   - Token not expired (`token_expiry > now`)
   - Order belongs to the currently logged-in user (check `wc_order_id` → order customer)
   - Status is not `refunded`
5. If valid:
   - Decrypt `serial_code_encrypted` using AES-256
   - Display the PIN code clearly on screen
   - Show product name, order date, expiry (if available)
   - Update `revealed_at` (if first time) and increment `reveal_count`
   - Update status to `revealed`
   - Show a copy-to-clipboard button next to the code
6. If invalid/expired:
   - Show a clear message explaining why
   - Provide a link to contact support

### Re-reveal

- Customer can reveal the same code multiple times (they may lose it)
- Each reveal increments `reveal_count` and logs a timestamp
- Token does NOT get invalidated after first reveal — it stays valid until expiry

---

## Refund Handling

Hook: `woocommerce_order_status_refunded` and `woocommerce_order_refunded`

- When a WooCommerce refund is processed:
  - Check if the order has rows in `wupex_codes`
  - If `revealed_at` is NOT null (code was revealed) — block the refund programmatically
    and show admin a notice: "Code was already revealed on {date}. Refund blocked."
  - If `revealed_at` IS null (code not yet revealed) — allow refund, set code status to
    `refunded`, invalidate the reveal token by setting `token_expiry` to now
- Add an order note either way logging what happened

---

## Admin Order View

On the WooCommerce order detail page (admin), add a meta box showing:

- Wupex order name
- Each code: SKU, serial number, status (unrevealed/revealed/refunded)
- Revealed at timestamp (if applicable)
- Reveal count
- A **Re-send Email** button to resend the code ready email to the customer
- A **View Reveal Page** link for each code

---

## API Wrapper Class

Create a class `Wupex_API` with these methods:

```php
class Wupex_API {
    public function get_balance(): array
    public function get_products(int $page, int $page_size, array $search = []): array
    public function pull_codes(string $sku, int $quantity, string $reference_id): array
    public function get_order_detail(string $order_name): array
}
```

- All methods read API key, merchant code, and base URL from plugin settings
- All methods return a consistent array: `['success' => bool, 'data' => array, 'error' => string]`
- Log all API errors to a custom log file: `wp-content/uploads/wupex-logs/wupex-{date}.log`
- Timeout: 30 seconds per request
- Use `wp_remote_post` and `wp_remote_get` (WordPress HTTP API)

---

## Error Handling & Logging

- All API calls wrapped in try/catch
- Failed Wupex orders add a WooCommerce order note visible to admin
- Failed orders flagged with order meta `_wupex_failed = 1` so admin can filter them
- Log file location: `wp-content/uploads/wupex-logs/wupex-YYYY-MM-DD.log`
- Log format: `[timestamp] [ORDER-ID] [ACTION] message`
- Add a **View Logs** button on the settings page showing last 100 log lines

---

## Security

- All PIN codes stored AES-256 encrypted using the encryption key from settings
- Reveal tokens are 32-character cryptographically random hex strings
- Reveal page validates that the token belongs to the logged-in user's order
- All admin pages check `current_user_can('manage_woocommerce')`
- All AJAX endpoints use WordPress nonces
- API key stored using WordPress `get_option` — never exposed in frontend source

---

## File Structure

```
wupex-gift-cards/
├── wupex-gift-cards.php          # Main plugin file, headers, activation hooks
├── includes/
│   ├── class-wupex-api.php       # API wrapper
│   ├── class-wupex-order.php     # Order hook handler
│   ├── class-wupex-crypto.php    # Encryption/decryption helpers
│   ├── class-wupex-db.php        # Database table creation and queries
│   ├── class-wupex-email.php     # Email templates and sending
│   └── class-wupex-reveal.php    # Reveal page/endpoint handler
├── admin/
│   ├── class-wupex-settings.php  # Settings page
│   ├── class-wupex-import.php    # Product import page
│   └── class-wupex-order-meta.php # Order detail meta box
├── templates/
│   ├── email-code-ready.php      # Code ready email template
│   └── reveal-page.php           # Reveal page template
├── assets/
│   ├── css/
│   │   └── wupex-admin.css
│   └── js/
│       └── wupex-admin.js
└── readme.txt
```

---

## Key Notes for Claude Code

- Use `woocommerce_order_status_completed` hook — only act on completed + paid orders
- Never store raw PIN codes in the database — always encrypt before insert
- The `referenceId` sent to Wupex must be unique per order item — use `WC-{order_id}-{item_id}-{time()}`
- `allowTakeAll=true` must always be in the pull-codes query string
- Multiple items in one WooCommerce order = multiple separate Wupex API calls (one per product line item)
- If a product does NOT have `_wupex_sku` meta, skip it silently — store may sell non-Wupex products too
- Customer must be logged in to access the reveal page
- Reveal tokens do not expire after first reveal — only after `token_expiry` datetime
- Run DB table creation on `register_activation_hook` and also check on `plugins_loaded` (for updates)
- Test connection button on settings page is an AJAX call — show spinner while loading
