# Magento MCP Server

[![Packagist Version](https://img.shields.io/packagist/v/yuriyakishin/module-mcp-server)](https://packagist.org/packages/yuriyakishin/module-mcp-server)
[![PHP Version](https://img.shields.io/packagist/dependency-v/yuriyakishin/module-mcp-server/php?label=php)](https://packagist.org/packages/yuriyakishin/module-mcp-server)
![Magento](https://img.shields.io/badge/magento-2.4.4%2B-orange)
[![License](https://img.shields.io/packagist/l/yuriyakishin/module-mcp-server)](https://github.com/yuriyakishin/magento2-mcp-server/blob/main/LICENSE)

An [MCP (Model Context Protocol)](https://modelcontextprotocol.io/) server implemented as
a Magento 2 module. It exposes store operations as typed, access-controlled MCP tools over
the Streamable HTTP transport, so MCP clients (claude.ai Connectors, Claude Desktop, etc.)
can read and manage the store from a chat session.

<!-- TODO: demo video / screenshots -->

## What the AI can do with it

Example phrases that resolve to tool calls:

**Catalog & content (anonymous)**
- *"Find sports bras under $40"* → `product_search`
- *"Show the full card for SKU WS02"* → `product_get`
- *"Is MT01 in stock?"* → `product_check_stock`
- *"What promotions are running right now?"* → `promotion_list`
- *"What payment and delivery options does the store offer?"* → `store_info`
- *"What currency will I be charged in? Convert this price to euros."* → `store_info`
- *"How can I contact the store?"* → `store_info`
- *"What do customers say about the Antonia Racer Tank?"* → `review_list_for_product`

**Business insights (admin login required)**
- *"How were sales last week? Compare to the week before."* → `sales_compare_periods`
- *"Which orders are stuck in processing?"* → `order_list`
- *"Show negative reviews from the last 7 days — judge by the text, not the stars."* → `review_list`
- *"List abandoned carts over $100 from this week."* → `cart_list_abandoned`
- *"Which products are almost out of stock?"* → `product_low_stock`
- *"Who are my top customers by lifetime spend?"* → `customer_search`
- *"Find the customer Emma — how much has she spent with us?"* → `customer_search`
- *"Find Maria and show her recent orders."* → `customer_search` + `order_list_by_customer`
- *"How fast is WS02 selling — when will it run out?"* → `product_sales_velocity`
- *"Check the sales pace of everything that's low on stock — what should I reorder first?"* → `product_low_stock` + `product_sales_velocity`
- *"Top 10 products by revenue this quarter."* → `sales_bestsellers`
- *"Compare June to May — orders, revenue, average check."* → `sales_compare_periods`
- *"Which categories actually drive revenue?"* → `sales_by_category`
- *"How do customers pay, and which delivery options do they pick?"* → `sales_payment_stats` + `sales_shipping_stats`
- *"What do visitors search for — and which searches return nothing?"* → `search_terms_report`
- *"What needs fixing in the catalog: missing images, descriptions, meta?"* → `catalog_health_report`
- *"Is the store healthy — indexers, cron, cache?"* → `system_health`
- *"List every CMS page — any forgotten drafts?"* → `cms_page_list`

**Store management (admin login + write switch)**
- *"Raise the price of WS02 by 10% and clean up its description."* → `product_update`
- *"Create a draft product for the new hoodie, keep it disabled."* → `product_create`
- *"Approve the positive pending reviews, reject the spam one."* → `review_moderate`
- *"Add a CMS block with the holiday shipping notice."* → `cms_block_create`
- *"Duplicate WS02 as WS02-RED for the red variant."* → `product_duplicate`
- *"Hide the Sale category from the menu and refresh its meta description."* → `category_update`

**Prompts (one-click workflows)**
- `morning_report` — the owner's daily digest: sales summary, orders needing action,
  review moderation queue with text-based sentiment, abandoned carts, low stock.
- `store_checkup` — the weekly health inspection: indexers/cron/cache, catalog content
  gaps, searches that find nothing, pending reviews and restock risks, prioritized into
  a "fix now / this week / backlog" plan.
- `recover_carts` — abandoned-cart triage plus a personalised recovery email draft per
  cart. Drafts only — nothing is sent.
- `restock_advisor` — a purchase table ranked by days-to-stockout: what runs out first
  and how much to reorder.
- `review_moderate_queue` — verdict per pending review, applied only after your explicit
  confirmation.
- `product_content_writer` — drafts missing product descriptions, one product per
  confirmation.
- `zero_result_rescue` — turns "searches that found nothing" into a naming-gap /
  assortment-gap worklist.
- `sales_dashboard` — an interactive dashboard with charts: revenue trend, categories,
  bestsellers, payment & shipping mix.
- `sales_trends` — this period vs the previous one (or last year), with percentage
  deltas and bestseller movers, as charts.
- `product_performance` — top products by revenue and by quantity with sales velocity;
  flags bestsellers about to run out of stock.

## Requirements

- Magento 2.4.4+ (developed on 2.4.7; uses no APIs newer than 2.4.4)
- PHP 8.1+ (8.1 / 8.2 / 8.3)
- No third-party dependencies

## Installation

### Composer (recommended)

```bash
composer require yuriyakishin/module-mcp-server
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
Claude Desktop: Settings → Connectors → Add custom connector). The flow is automatic:

1. The client reads the discovery documents (`/.well-known/oauth-protected-resource`,
   `/.well-known/oauth-authorization-server`) and registers itself via Dynamic Client
   Registration (RFC 7591).
2. Public tools answer immediately. The first call to a restricted tool returns
   HTTP 401 + `WWW-Authenticate` with a `resource_metadata` pointer, which triggers the
   client's OAuth flow.
3. The user's browser lands on a login form backed by Magento's own admin authentication
   (`\Magento\Backend\Model\Auth` — lockout and 2FA apply). The issued token carries that
   admin's ACL permissions.

## Protocol

Single endpoint `POST /mcp`, JSON-RPC 2.0, MCP Streamable HTTP transport. Batch requests
supported. `GET /mcp` opens an empty SSE stream (some clients probe with GET before
registering).

| Method | Notes |
|---|---|
| `initialize` | Version negotiation: echoes the client's `protocolVersion` if supported (2025-11-25 / 2025-06-18 / 2025-03-26 / 2024-11-05), otherwise answers with the newest. Capabilities: `tools`, `prompts` |
| `notifications/initialized` | Notification, no response body (HTTP 202) |
| `tools/list` | All registered tools with JSON Schema arguments. Not filtered by identity — restricted tools must stay discoverable so clients can hit the 401 that starts the login flow. Write tools are hidden while the write switch is off |
| `tools/call` | ACL enforcement happens here. Tool business-logic errors come back as `result.isError = true`, never as JSON-RPC errors |
| `prompts/list` / `prompts/get` | Prompt templates with declared arguments |
| `ping` | Health check |

Error semantics: anonymous call to a restricted tool → HTTP 401 (+ `WWW-Authenticate`);
authenticated but missing the ACL resource → JSON-RPC error `-32002` (Forbidden) in a 200
response; over the anonymous rate limit → HTTP 429 + `Retry-After`.

## Tools

40 tools, one class each in a domain subfolder of `Model/Tools/` (`Product/`, `Order/`,
`Review/`, ...), registered via DI in `etc/di.xml`. Tool names are domain-first
(`{domain}_{action}`), so a sorted `tools/list` groups by domain and the name prefix
matches the class's subfolder. Access is declared per tool through
`getRequiredAclResource()`: `null` = public, otherwise the Magento ACL resource the
caller's admin role must hold.

### Public (anonymous, rate-limited)

| Tool | Purpose |
|---|---|
| `product_search` | Search by name/SKU |
| `product_get` | Full product card by SKU |
| `product_check_stock` | Qty + in-stock flag for up to 50 SKUs |
| `category_tree` | Category tree |
| `category_products` | Products of a category |
| `promotion_list` | Active cart rules (coupon codes never exposed) |
| `review_list_for_product` | Approved reviews of a product |
| `cms_page_get` / `cms_block_get` | Active CMS content by identifier |
| `store_info` | Currencies, locale, active shipping/payment methods (code + title only), public contacts |

Public tools return the **customer view**: disabled products are indistinguishable from
nonexistent ones. An authenticated admin holding `Magento_Catalog::products` gets the
**admin view** through the same tools — disabled products included and marked with
`status` (`ContextAwareToolInterface`).

### Read, ACL-gated

| Tool | ACL resource |
|---|---|
| `order_get` | `Magento_Sales::sales` |
| `order_list_by_customer` | `Magento_Sales::sales` |
| `order_list` | `Magento_Sales::sales` |
| `sales_summary` | `Magento_Sales::sales` |
| `cart_list_abandoned` | `Magento_Reports::abandoned` |
| `product_low_stock` | `Magento_Catalog::products` |
| `review_list` | `Magento_Review::reviews_all` |
| `customer_search` | `Magento_Customer::manage` |
| `product_sales_velocity` | `Magento_Sales::sales` |
| `sales_bestsellers` | `Magento_Sales::sales` |
| `sales_by_category` | `Magento_Sales::sales` |
| `sales_compare_periods` | `Magento_Sales::sales` |
| `sales_payment_stats` | `Magento_Sales::sales` |
| `sales_shipping_stats` | `Magento_Sales::sales` |
| `search_terms_report` | `Magento_Reports::report_search` |
| `catalog_health_report` | `Magento_Catalog::products` |
| `cms_page_list` | `Magento_Cms::page` |
| `cms_block_list` | `Magento_Cms::block` |
| `system_health` | `Magento_Backend::system` |

The sales tools aggregate over collection rows in PHP with documented scan limits (a
`truncated: true` flag appears when a period exceeds them). `system_health` is read-only
by design: it reports invalid indexers, failed/stuck cron jobs and disabled/invalidated
caches, but fixing them stays admin work.

### Write, ACL-gated + write switch

| Tool | ACL resource | Limits |
|---|---|---|
| `product_create` | `Magento_Catalog::products` | Simple products; created **disabled** unless explicitly enabled |
| `product_update` | `Magento_Catalog::products` | name, price, special_price (incl. removal), descriptions, status. No SKU/category/image changes |
| `product_update_stock` | `Magento_Catalog::products` | Status + qty only, batch by SKU, all-or-nothing |
| `category_create` | `Magento_Catalog::categories` | Duplicate sibling name = error, never an implicit update |
| `cms_page_create` / `cms_block_create` | `Magento_Cms::save` / `Magento_Cms::block` | Created inactive by default |
| `cms_page_update` / `cms_block_update` | `Magento_Cms::save` / `Magento_Cms::block` | Content/title/active flag; identifier immutable; content can't be emptied |
| `review_moderate` | `Magento_Review::reviews_all` | Status-only (approve/reject), batch, all-or-nothing. Review text/rating never editable |
| `category_update` | `Magento_Catalog::categories` | name, description, active flag, menu visibility, meta fields. No URL key changes or parent moves; root categories rejected |
| `product_duplicate` | `Magento_Catalog::products` | Simple products; the copy is always created **disabled** with zero stock |

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

## Logging

Dedicated channel `var/log/mcp.log` (method, tool name, duration, success/failure; write
audit records). Payload bodies are logged only at debug level behind the config flag.

## Development

Adding a tool: implement `ToolInterface` (or `WriteToolInterface` /
`ContextAwareToolInterface`) as one class in `Model/Tools/`, register it in
`etc/di.xml` under `ToolRegistry`'s `tools` argument, add a unit test. Rules: DI only (no
ObjectManager), data access through repositories/collections (no raw SQL), tools never see
JSON-RPC — they return arrays or throw exceptions.

```bash
# unit tests (291; run setup:di:compile once first — some tests mock generated factories)
vendor/bin/phpunit --no-configuration app/code/Yu/McpServer/Test/Unit

# coding standard
vendor/bin/phpcs --standard=Magento2 app/code/Yu/McpServer
```
