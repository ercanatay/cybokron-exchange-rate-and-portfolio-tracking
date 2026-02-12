# Sentinel Journal

## 2026-02-12 - API Exception Disclosure Pattern
**Vulnerability:** `api.php` returned raw exception messages directly to clients, exposing internal errors (including potential database/application details).
**Learning:** Error handling focused on developer visibility, but lacked a separate safe message path for external API consumers.
**Prevention:** Centralize API error responses around generic public messages and always log internal exception details server-side.

