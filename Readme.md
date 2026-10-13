## TLD Pricing (Dynadot RESTful API)

### Endpoint

GET https://api.dynadot.com/restful/v2/domains/get_tld_price

Example:
https://api.dynadot.com/restful/v2/domains/get_tld_price?currency=USD&tlds=com,net,org,io,xyz&show_multi_year=true

- No request body (GET request).
- Required headers:
  - Authorization: Bearer YOUR_API_KEY
  - Accept: application/json

### Query parameters

- currency: price currency, e.g. USD
- tlds: comma-separated list of TLDs to return, e.g. com,net,org,io,xyz (omit to get the full list)
- show_multi_year: true to include prices for 1 to max_duration years
- page_index, count_per_page: pagination when listing all TLDs
- sort: e.g. name_asc, rank_asc

### Response (per TLD, inside data.tld_price_list[])

- tld: extension, e.g. ".xyz"
- all_years_register_price: array of CUMULATIVE totals for registering 1, 2, 3 ... years
- all_years_renew_price: array of CUMULATIVE totals for renewing 1, 2, 3 ... years
- transfer_price: single price (1 year transfer)
- min_duration / max_duration: allowed registration years (e.g. 1 and 10)
- on_sale: NOT reliable for detecting promos (see below)

### How the multi-year prices work

The arrays hold totals, not per-year prices. Index 0 = total for 1 year, index 1 = total for 2 years, and so on. The price of a single year is the difference between neighbouring entries. This is how Dynadot represents a discounted first year.

Example (.xyz):
- Register totals: 1.99, 9.99, 24.44, 38.89 ...
  - Per year: 1.99 (promo), 8.00, 14.45, 14.45 ...
- Renew totals: 14.45, 28.89, 43.34 ...
  - Per year: 14.45

Example (.com, no promo): register and renew are identical and flat (10.88 per year), so totals are just 10.88 x years.

Because of the promo pricing, the total for n years must NOT be calculated as
first_year + renew x (n-1), or as register x n. For .xyz, registering 2 years
costs 9.99, not 1.99 x 2 = 3.98 and not 1.99 + 14.45 = 16.44.
Always read the total for the exact duration from the array.

A promo can be detected with: all_years_register_price[0] < all_years_renew_price[0]
(the on_sale flag returned "No" for .xyz, .org and .io even though their first year was discounted).

### What changed in the module

Before: tld_catalogue() used the legacy api3 "tld_price" command, kept one per-year price per TLD, and tlds() computed multi-year prices by multiplying it by the number of years. This produced wrong totals for any TLD with promo pricing.

After:
- ApiClient::rest() calls the RESTful endpoint above (Bearer token, Accept: application/json) and retries on HTTP 429 (rate limit).
- tld_catalogue() reads get_tld_price with show_multi_year=true and stores the cumulative totals for each year.
- tlds() writes those totals directly as the register/renewal cost for each year. No multiplication.
- cost_prices() still returns per-year register, renewal and transfer values, now based on the year-1 totals, so a promo first year is reported as the register cost.

### Notes

- Rate limit: 1 request per second on a regular account. Pricing is fetched in a few paged requests during import, so cache the result rather than calling it per customer request.
- Coupon codes are NOT reflected in these prices. Registration uses the configured coupons at order time, so the actual cost can be lower than the stored cost.
- Promo prices change over time, so re-run the cost update periodically.