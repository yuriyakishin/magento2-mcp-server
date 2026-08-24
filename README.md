# Magento 2 MCP Server

[![Packagist Version](https://img.shields.io/packagist/v/yu-dev/module-mcp-server)](https://packagist.org/packages/yu-dev/module-mcp-server)
[![PHP Version](https://img.shields.io/packagist/dependency-v/yu-dev/module-mcp-server/php?label=php)](https://packagist.org/packages/yu-dev/module-mcp-server)
![Magento](https://img.shields.io/badge/magento-2.4.4%2B-orange)
[![License](https://img.shields.io/packagist/l/yu-dev/module-mcp-server)](https://github.com/yuriyakishin/magento2-mcp-server/blob/main/LICENSE)

This Magento 2 module lets an AI assistant — Claude or any other
[MCP](https://modelcontextprotocol.io/)-compatible client — connect directly to your store:
it can answer questions about your catalog, orders, customers, and sales, and, with the
right permissions, make changes on your behalf, all through a normal chat conversation.

It implements the MCP (Model Context Protocol) open standard and exposes 40+ tools covering
product search, order management, sales analytics, CMS content, customer data and more —
each gated by Magento ACL, so the AI can only touch what the logged-in administrator is
authorized to use.

## Demo video

[![MCP Server demo video](https://img.youtube.com/vi/dlniqgmraqM/maxresdefault.jpg)](https://www.youtube.com/watch?v=dlniqgmraqM)

Connecting an AI assistant to a Magento store with **OAuth 2.1 and PKCE**, followed by a
live walkthrough of several MCP tools.

## Requirements

- Magento 2.4.4+ (developed on 2.4.7; uses no APIs newer than 2.4.4)
- PHP 8.1+ (8.1 / 8.2 / 8.3)
- No third-party dependencies

## Installation

### Composer (recommended)

```bash
composer require yu-dev/module-mcp-server
bin/magento module:enable Yu_McpServer
bin/magento setup:upgrade        # creates the mcp_oauth_* tables
bin/magento setup:di:compile
bin/magento cache:flush
```

### Manual (app/code)

Copy the module to `app/code/Yu/McpServer` (e.g. `git clone` into that path), then run
the same `bin/magento` commands as above.

Smoke test:

```bash
curl -s -X POST https://your-store.com/mcp \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

## Configuration

Stores → Configuration → Advanced → MCP Server:

| Setting | Default | Effect |
|---|---|---|
| Enable Public (Anonymous) Tools | Yes | Anonymous `tools/call` on ACL-free tools. Off = every tool call requires an authenticated admin; `tools/list` stays open for OAuth discovery |
| Enable Write Tools | No | Master switch for all `WriteToolInterface` tools. Off = hidden from `tools/list`, rejected at `tools/call` |
| Log Full Request/Response Bodies | No | Debug-level dump of raw JSON-RPC payloads to `var/log/mcp.log`. May contain personal data — enable only while debugging |

## Connecting a client

Add `https://your-store.com/mcp` as a custom connector (claude.ai: Settings → Connectors;
Claude Desktop: Settings → Connectors → Add custom connector). Public tools answer right
away; the first restricted tool call opens your browser on a login form backed by Magento's
own admin authentication (lockout and 2FA apply as usual). The issued token carries that
admin's ACL permissions — no API key to copy anywhere. See [Protocol](#protocol) for the
discovery/registration mechanics behind this.

## Tools

40 tools, organized by domain below (see [Prompts](#prompts) for multi-step workflows).
**Access**: Public = anonymous · Read — `<ACL resource>` = admin holding that resource ·
Write — `<ACL resource>` = same, plus the "Enable Write Tools" switch (off by default).

### Product

| Tool | Access | Purpose | Example |
|---|---|---|---|
| `product_search` | Public | Search by name/SKU | *"Find sports bras under $40"* |
| `product_get` | Public | Full product card by SKU | *"Show the full card for SKU WS02"* |
| `product_check_stock` | Public | Qty + in-stock flag for up to 50 SKUs | *"Is MT01 in stock?"* |
| `product_low_stock` | Read — `Magento_Catalog::products` | Products below a qty threshold, lowest first | *"Which products are almost out of stock?"* |
| `product_sales_velocity` | Read — `Magento_Sales::sales` | Sales pace + stockout forecast per SKU | *"How fast is WS02 selling — when will it run out?"* |
| `product_create` | Write — `Magento_Catalog::products` | Simple products; created **disabled** unless explicitly enabled | *"Create a draft product for the new hoodie, keep it disabled."* |
| `product_update` | Write — `Magento_Catalog::products` | name, price, special_price (incl. removal), descriptions, status. No SKU/category/image changes | *"Raise the price of WS02 by 10% and clean up its description."* |
| `product_update_stock` | Write — `Magento_Catalog::products` | Status + qty only, batch by SKU, all-or-nothing | *"Mark WS02 out of stock and set its qty to 0."* |
| `product_duplicate` | Write — `Magento_Catalog::products` | Simple products; the copy is always created **disabled** with zero stock | *"Duplicate WS02 as WS02-RED for the red variant."* |

Public `product_*`/`category_*` tools return the **customer view**: disabled products are
indistinguishable from nonexistent ones. An authenticated admin holding
`Magento_Catalog::products` gets the **admin view** through the same tools — disabled
products included and marked with `status` (`ContextAwareToolInterface`).

### Category

| Tool | Access | Purpose | Example |
|---|---|---|---|
| `category_tree` | Public | Category tree | *"What categories does the store have?"* |
| `category_products` | Public | Products of a category | *"List the products in the Sale category."* |
| `category_create` | Write — `Magento_Catalog::categories` | Duplicate sibling name = error, never an implicit update | *"Add a new category 'Winter Collection' under Women."* |
| `category_update` | Write — `Magento_Catalog::categories` | name, description, active flag, menu visibility, meta fields. No URL key changes or parent moves; root categories rejected | *"Hide the Sale category from the menu and refresh its meta description."* |

### Order

| Tool | Access | Purpose | Example |
|---|---|---|---|
| `order_get` | Read — `Magento_Sales::sales` | Order status/details by order number | *"What's the status of order 000000123?"* |
| `order_list_by_customer` | Read — `Magento_Sales::sales` | Orders placed by a specific customer | *"Find Maria and show her recent orders."* |
| `order_list` | Read — `Magento_Sales::sales` | Orders across the store, filterable by status | *"Which orders are stuck in processing?"* |

### Customer

| Tool | Access | Purpose | Example |
|---|---|---|---|
| `customer_search` | Read — `Magento_Customer::manage` | Name/email search + order count and lifetime spend | *"Who are my top customers by lifetime spend?"* |

### Review

| Tool | Access | Purpose | Example |
|---|---|---|---|
| `review_list_for_product` | Public | Approved reviews of a product | *"What do customers say about the Antonia Racer Tank?"* |
| `review_list` | Read — `Magento_Review::reviews_all` | Reviews store-wide, any moderation status, date/rating filters | *"Show negative reviews from the last 7 days — judge by the text, not the stars."* |
| `review_moderate` | Write — `Magento_Review::reviews_all` | Status-only (approve/reject), batch, all-or-nothing. Review text/rating never editable | *"Approve the positive pending reviews, reject the spam one."* |

### CMS

| Tool | Access | Purpose | Example |
|---|---|---|---|
| `cms_page_get` / `cms_block_get` | Public | Active CMS content by identifier | *"What does the 'About Us' page say?"* |
| `cms_page_list` | Read — `Magento_Cms::page` | All pages, including inactive drafts | *"List every CMS page — any forgotten drafts?"* |
| `cms_block_list` | Read — `Magento_Cms::block` | All blocks, including inactive ones | *"List every CMS block, including inactive ones."* |
| `cms_page_create` / `cms_block_create` | Write — `Magento_Cms::save` / `Magento_Cms::block` | Created inactive by default | *"Add a CMS block with the holiday shipping notice."* |
| `cms_page_update` / `cms_block_update` | Write — `Magento_Cms::save` / `Magento_Cms::block` | Content/title/active flag; identifier immutable; content can't be emptied | *"Update the return-policy page with the new 30-day window."* |

### Sales

| Tool | Access | Purpose | Example |
|---|---|---|---|
| `sales_summary` | Read — `Magento_Sales::sales` | Revenue/orders headline + top products for a period | *"How were sales last week?"* |
| `sales_bestsellers` | Read — `Magento_Sales::sales` | Top products by qty/revenue for a period | *"Top 10 products by revenue this quarter."* |
| `sales_by_category` | Read — `Magento_Sales::sales` | Revenue per category (current assignments) | *"Which categories actually drive revenue?"* |
| `sales_compare_periods` | Read — `Magento_Sales::sales` | Two periods + server-side % deltas | *"Compare June to May — orders, revenue, average check."* |
| `sales_payment_stats` | Read — `Magento_Sales::sales` | Orders/revenue per payment method | *"How do customers pay?"* |
| `sales_shipping_stats` | Read — `Magento_Sales::sales` | Orders/revenue/charges per shipping method | *"Which delivery options do customers pick?"* |

All `sales_*` tools aggregate over collection rows in PHP with documented scan limits (a
`truncated: true` flag appears when a period exceeds them).

### Search

| Tool | Access | Purpose | Example |
|---|---|---|---|
| `search_terms_report` | Read — `Magento_Reports::report_search` | Popular + zero-result storefront search terms | *"What do visitors search for — and which searches return nothing?"* |

### Catalog

| Tool | Access | Purpose | Example |
|---|---|---|---|
| `catalog_health_report` | Read — `Magento_Catalog::products` | Missing images/descriptions/meta, zero prices | *"What needs fixing in the catalog: missing images, descriptions, meta?"* |

### System

| Tool | Access | Purpose | Example |
|---|---|---|---|
| `system_health` | Read — `Magento_Backend::system` | Indexers, cron (24h, errors, stuck), cache state | *"Is the store healthy — indexers, cron, cache?"* |

`system_health` is read-only by design: it reports invalid indexers, failed/stuck cron
jobs and disabled/invalidated caches, but fixing them stays admin work.

### Marketing

| Tool | Access | Purpose | Example |
|---|---|---|---|
| `promotion_list` | Public | Active cart rules (coupon codes never exposed) | *"What promotions are running right now?"* |
| `cart_list_abandoned` | Read — `Magento_Reports::abandoned` | Abandoned carts incl. customer email, filterable by age/value | *"List abandoned carts over $100 from this week."* |

### Store

| Tool | Access | Purpose | Example |
|---|---|---|---|
| `store_info` | Public | Currencies, locale, active shipping/payment methods (code + title only), public contacts | *"What payment and delivery options does the store offer?"* |

There are **no delete tools** of any kind, by design. Every successful write call is
audit-logged (`var/log/mcp.log`, info level, no customer personal data).

## Prompts

| Prompt | Arguments | Purpose |
|---|---|---|
| `morning_report` | `period` (optional, default `yesterday`) | Renders instructions that walk the model through `sales_summary`, `order_list` (processing), `review_list` (pending, sentiment judged from text), `cart_list_abandoned`, `product_low_stock`, and format a digest. Explicitly forbids calling `review_moderate` without the owner's confirmation |
| `store_checkup` | `period` (optional, default `last 30 days`) | Full health inspection: `system_health`, `catalog_health_report`, `search_terms_report` (zero-result demand), pending `review_list`, `product_low_stock` + `product_sales_velocity` restock ranking — summarized into a "Fix now / This week / Backlog" action plan. Diagnose-only: forbids all write calls during the checkup |
| `recover_carts` | `days` (optional, default `7`, 1-30) | Abandoned-cart triage (chase now / batch reminder / skip) plus a personalised recovery email draft per cart worth chasing. Drafts only — nothing is sent, and the model may not invent discounts on its own |
| `restock_advisor` | `horizon_days` (optional, default `30`, 7-90) | Purchase plan: `product_low_stock` + per-SKU `product_sales_velocity`, ranked by estimated days until stockout, with reorder quantities sized to cover the horizon. Read-only — forbids `product_update_stock`; the table goes to the supplier, not back into Magento |
| `review_moderate_queue` | `created_from` (optional, `YYYY-MM-DD`) | Walks the pending review queue with an approve / reject / needs-owner verdict per review (sentiment judged from the text, not the stars), then applies via `review_moderate` — only after the owner's explicit confirmation, max 20 ids per call |
| `review_negative_report` | `created_from`, `created_to` (optional, `YYYY-MM-DD`, default last 7 days) | Scans `review_list` across all moderation statuses over the date window, with no rating filter (a rating is optional and often missing, so filtering on it would silently drop unrated reviews). Reports only the reviews that read negative by their text, grouped by recurring theme; proposes a verdict for the `pending` subset using the same approve / reject / needs-owner rules as `review_moderate_queue`, applied via `review_moderate` only after the owner's explicit confirmation. Reviews already `approved`/`not_approved` are reported only, never re-verdicted |
| `product_content_writer` | `limit` (optional, default `5`, 1-10) | Drafts the descriptions `catalog_health_report` found missing and applies them via `product_update` strictly one product per confirmation. Meta descriptions are drafted but handed to the owner — the update tool deliberately can't write meta fields |
| `zero_result_rescue` | — | Storefront searches that returned nothing → probes the catalog for synonyms/translations/typos with `product_search` → classifies each term as a naming gap (with the exact word to add to which SKU), an assortment gap (with demand numbers) or noise. Read-only worklist |
| `sales_dashboard` | `period` (optional, default `last 30 days`) | Interactive dashboard rendered as charts: KPI row (revenue / orders / average order value), a trend bucketed by day/week/month via repeated `sales_summary` calls, revenue by category, bestsellers, payment & shipping mix, plus takeaways. Falls back to markdown tables in clients without artifact support |
| `sales_trends` | `period`, `compare_to` (optional, default `last 30 days` vs the same-length period immediately before) | Two-period comparison: KPI cards with percentage deltas (computed server-side by `sales_compare_periods`, never re-derived by the model), a current-vs-baseline chart per currency, and the bestseller movers between the two periods |
| `product_performance` | `period`, `top` (optional, default `last 30 days` / `10`, 1-15) | Product charts: top sellers by revenue AND by quantity (the mismatches are the interesting ones), each enriched with `product_sales_velocity`; highlights bestsellers with under 14 days of stock cover. Read-only |

## Security

- **OAuth 2.1 Authorization Code + PKCE** (S256 only), access + refresh tokens, refresh
  rotation on every use, single-use 60s auth codes, per-IP throttling of failed logins
  (5 / 15 min). Public clients only — no client secrets.
- **Identity = Magento admin, permissions = Magento ACL.** No parallel user store. A full
  Administrator role (`Magento_Backend::all`) is treated as a wildcard.
- **Anonymous surface:** rate-limited 60 requests/IP/min (proxy-aware client IP — works
  behind Cloudflare Tunnel / reverse proxies via `X-Forwarded-For`), optionally disableable
  entirely via the public-tools switch.
- **Dedicated OAuth storage:** `mcp_oauth_client` / `mcp_oauth_auth_code` /
  `mcp_oauth_token` tables (separate from Magento's OAuth 1.0a Integration tokens, which
  can't serve this flow).
- Presented-but-invalid tokens are a hard 401 — never silently downgraded to anonymous.
- **Error semantics:** anonymous call to a restricted tool → HTTP 401 (+ `WWW-Authenticate`);
  authenticated but missing the ACL resource → JSON-RPC error `-32002` (Forbidden) in a 200
  response; over the anonymous rate limit → HTTP 429 + `Retry-After`.

## Logging

Dedicated channel `var/log/mcp.log` (method, tool name, duration, success/failure; write
audit records). Payload bodies are logged only at debug level behind the config flag.

## Protocol

Single endpoint `POST /mcp`, JSON-RPC 2.0, MCP Streamable HTTP transport. Batch requests
supported. `GET /mcp` opens an empty SSE stream (some clients probe with GET before
registering).

**OAuth discovery:** the client reads `/.well-known/oauth-protected-resource` and
`/.well-known/oauth-authorization-server`, then registers itself via Dynamic Client
Registration (RFC 7591). Public tools answer without a token; the first call to a
restricted tool returns HTTP 401 + `WWW-Authenticate` with a `resource_metadata` pointer,
which is what triggers the client's OAuth flow (see [Security](#security) for the
credential/token handling itself).

| Method | Notes |
|---|---|
| `initialize` | Version negotiation: echoes the client's `protocolVersion` if supported (2025-11-25 / 2025-06-18 / 2025-03-26 / 2024-11-05), otherwise answers with the newest. Capabilities: `tools`, `prompts` |
| `notifications/initialized` | Notification, no response body (HTTP 202) |
| `tools/list` | All registered tools with JSON Schema arguments. Not filtered by identity — restricted tools must stay discoverable so clients can hit the 401 that starts the login flow. Write tools are hidden while the write switch is off |
| `tools/call` | ACL enforcement happens here. Tool business-logic errors come back as `result.isError = true`, never as JSON-RPC errors |
| `prompts/list` / `prompts/get` | Prompt templates with declared arguments |
| `ping` | Health check |

## Development

Adding a tool: implement `ToolInterface` (or `WriteToolInterface` /
`ContextAwareToolInterface`) as one class in `Model/Tools/`, register it in
`etc/di.xml` under `ToolRegistry`'s `tools` argument, add a unit test. Rules: DI only (no
ObjectManager), data access through repositories/collections (no raw SQL), tools never see
JSON-RPC — they return arrays or throw exceptions.

```bash
# unit tests (291; run setup:di:compile once first — some tests mock generated factories)
vendor/bin/phpunit --no-configuration app/code/Yu/McpServer/Test/Unit

```

## Author

Yuriy Akishin:
- 📧 Email: yuriy.akishin@gmail.com
- 💼 LinkedIn: https://www.linkedin.com/in/yuriyakishin/
- 💻 GitHub: https://github.com/yuriyakishin
