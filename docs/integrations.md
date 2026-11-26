# Tracking integrations: what each adapter sends

One event layer feeds every service (PRD section 14). This file records the rules each adapter follows and
where they come from. "Read" means the service's own documentation was read while building (November 2026).
"Standard snippet" means the service's documentation could not be opened from the build machine, so the adapter
uses the service's long-standing public snippet and must be confirmed with that service's debug tool before launch.

## Google tag (GA4 and Google Ads) — read
- Order on the page: define `dataLayer` and `gtag`, call `gtag('consent','default',{ad_storage, ad_user_data, ad_personalization, analytics_storage})`,
  then load `https://www.googletagmanager.com/gtag/js?id=ID`, then `gtag('js', new Date())` and one `gtag('config', ID)` per destination.
- After the visitor chooses: `gtag('consent','update',{...})` with `granted` or `denied`.
- Events: `view_item_list` (item_list_id, item_list_name, items), `view_item` (currency, value, items), `search` (search_term),
  `add_to_cart`, `begin_checkout`, `add_payment_info` (currency, value, items), `purchase` (transaction_id, value, tax, shipping, currency, coupon, items), `sign_up` (method).
  There is no recommended event for a review, so `submit_review` is sent as a custom event.
- Item fields: item_id, item_name, item_variant, item_category, item_brand, price, quantity, index.
- Google Ads conversions: `gtag('event','conversion',{send_to: 'AW-ID/LABEL', value, currency, transaction_id})`.

## Google Tag Manager — read (consent), standard snippet (ecommerce data layer)
- Same consent default before the container loads. Events are pushed as `{event, ecommerce: {...}}` after `{ecommerce: null}`.

## Meta Pixel — read
- `fbq('consent','revoke')` before `fbq('init', ID)` on every page when consent is needed, `fbq('consent','grant')` after the visitor agrees.
- Deduplication with the server: same event name and `eventID` (browser) = `event_id` (server), 48 hour window.
  `fbq('track', 'Purchase', {value, currency, ...}, {eventID})`.

## Meta Conversions API — read
- `POST https://graph.facebook.com/{version}/{pixel id}/events?access_token=...` with `{data: [...], test_event_code?}`.
- Event: event_name, event_time (Unix seconds, not older than 7 days), event_id, action_source `website`, event_source_url, user_data, custom_data.
- user_data: em, ph, fn, ln, ct, st, zp, country are SHA-256 of the normalised value (trimmed, lowercase; phone digits only with country code);
  client_ip_address, client_user_agent, fbp, fbc are sent as they are.
- Purchase custom_data: value, currency, content_ids, contents [{id, quantity, item_price}], content_type `product`, order_id. `num_items` only on InitiateCheckout.

## TikTok — read (event names, deduplication), standard snippet (base code, Events API request)
- Event names: ViewContent, Search (search_string), AddToCart, InitiateCheckout, AddPaymentInfo, Purchase, CompleteRegistration.
  `CompletePayment` is the old name for Purchase.
- Parameters: content_type, content_ids / contents, quantity, currency, value.
- Deduplication: same event and event_id from the pixel and the Events API, 48 hour window.
- To confirm on the owner's account: the Events API request (`/open_api/v1.3/event/track/`, `Access-Token` header) with TikTok's Test Events tool.

## Pinterest — read
- `pintrk('load', ID); pintrk('page');` from `https://s.pinimg.com/ct/core.js`.
- Events: pagevisit, viewcategory, search (search_query), addtocart, checkout, signup.
  Data: value, order_quantity, currency, order_id, event_id, line_items [{product_id, product_name, product_price, product_quantity}].

## Snapchat — read (event names, parameters), standard snippet (base code)
- Events: PAGE_VIEW, VIEW_CONTENT, SEARCH, ADD_CART, START_CHECKOUT, ADD_BILLING, PURCHASE, SIGN_UP.
- Data: price, currency, transaction_id, item_ids, item_category, number_items, search_string, client_dedup_id (the event id).

## Microsoft Advertising (UET) — read (revenue and consent calls from Microsoft support answers), standard snippet (base code)
- Revenue: `window.uetq.push('event', 'purchase', {revenue_value, currency})`, currency in capitals, decimal point.
- Consent: `window.uetq.push('consent','default',{ad_storage:'denied'})` then `'update'` with `granted`.
