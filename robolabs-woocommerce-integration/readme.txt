=== RoboLabs WooCommerce Integration ===
Contributors: robolabs
Tags: woocommerce, invoicing, integration, accounting
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrates WooCommerce with RoboLabs invoicing (ROBO) API with async processing and idempotent sync.

== Description ==
RoboLabs WooCommerce Integration connects your WooCommerce store to the RoboLabs API, creating and confirming invoices asynchronously via Action Scheduler. It supports sandbox and production URLs, idempotent external IDs, refunds (full cancel and partial credit + reconcile), and admin tools to test and resync orders.

== Installation ==
1. Upload the `robolabs-woocommerce-integration` folder to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins**.
3. Navigate to **WooCommerce > RoboLabs** and configure settings.
4. Ensure Action Scheduler is available (WooCommerce bundles it).

== Settings Setup ==
Required settings:
- Base URL (sandbox or production)
- API Key
- Journal ID
- Default Product Category ID (categ_id)
- Invoice type and credit note invoice type (ex: out_invoice / out_refund)

Optional settings:
- Trigger rule (order created / payment complete / status processing / status completed)
- Language (en_US or lt_LT)
- Execute Immediately header
- Tax mode (RoboLabs decides vs pass WooCommerce taxes)
- Logging and job retry tuning

== Sandbox Testing Steps ==
1. Set Base URL to Sandbox.
2. Enter sandbox API key.
3. Test connection with **Test Connection**.
4. Place a test order and confirm a background job is scheduled.
5. Verify invoice creation and status in RoboLabs.

== Switching to Production ==
1. Update Base URL to Production (or define ROBOLABS_API_BASE).
2. Replace API key with production key.
3. Update journal_id, categ_id, and invoice types per accounting requirements.

== Troubleshooting ==
- 401/403: Check API key and permissions.
- Missing categ_id: Ensure Default Product Category ID is configured.
- Missing journal_id: Set Default Journal ID in settings.
- Job polling: If API returns job_id, Action Scheduler will poll /apiJob/{id}.
- Rate limit: 429 responses are logged and job retries will handle backoff.

== Support / Operations ==
- View logs: WooCommerce > Status > Logs (source: `robolabs-woocommerce`).
- Retry failed jobs: WooCommerce > Status > Scheduled Actions.
- Resync safely: Use the **Resync** button in order admin or the admin tools panel.

== Manual QA Checklist ==
- Partner creation and mapping saved.
- Product creation using configured categ_id.
- Invoice creation + confirm.
- Threaded job polling.
- Full refund cancels invoice.
- Partial refund creates credit + reconcile.
- Duplicate trigger does not create duplicates.

== Development ==
Unit tests (requires WP/Woo test suite):
- `vendor/bin/phpunit`

== Privacy ==
This plugin does not send personal data to any third-party other than RoboLabs API endpoints configured by the site administrator.
