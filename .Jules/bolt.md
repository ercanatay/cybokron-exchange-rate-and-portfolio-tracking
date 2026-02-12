# Bolt Journal

## 2026-02-12 - [Periodic DOM Lookup Hotspot]
**Learning:** The dashboard refresh loop performed repeated CSS selector queries for every rate row on each interval tick, creating avoidable DOM traversal overhead that scales linearly with table size.
**Action:** For any recurring UI update path in this codebase, build and reuse a stable element cache keyed by business identifiers (e.g., bank+currency) instead of querying the DOM inside the hot loop.

## 2026-02-12 - [Reuse last_rate_update as API version stamp]
**Learning:** `settings.last_rate_update` is already maintained by the cron flow, so it can be used as a cheap cache/version key to short-circuit unchanged `rates` API responses.
**Action:** For rate-polling endpoints in this project, check this setting first and return an unchanged payload when client and server versions match.

## 2026-02-12 - [Scraper double-parses identical HTML]
**Learning:** A single scrape run parses the same HTML twice (`computeTableHash` and bank `scrape`), which duplicates DOMDocument/XPath cost in the hottest cron path.
**Action:** Refactor scraper flow so table hash and row extraction share one parsed DOM/XPath instance.

## 2026-02-12 - [Rate persistence is round-trip heavy]
**Learning:** `saveRates` performs two SQL writes per rate row (upsert current + insert history), making DB round-trips scale linearly with currency count.
**Action:** Use bulk upsert/insert statements per bank scrape cycle to collapse per-rate writes into batch writes.

