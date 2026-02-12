# Cybokron v1.3.1

## Improved
- API error/success messages and endpoint descriptions are now fully localized via i18n keys
- Broader translation coverage for UI, admin, observability, alerts, and CSV export across `tr`, `en`, `ar`, `de`, `fr`
- Chart labels and theme-toggle accessibility labels now honor locale-specific strings
- Portfolio analytics tooltip formatting is now locale-aware

## Fixed
- Login redirect validation hardened to prevent unsafe redirect targets
- Portfolio form validation now maps errors to field-level localized messages more accurately
- Dashboard portfolio summary now uses consistent metrics (`total_value`, `profit_percent`)

## Compatibility
- No breaking changes
- Compatible with PHP 8.3 and 8.4
