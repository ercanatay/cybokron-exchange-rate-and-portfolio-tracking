# Sentinel Journal

## 2026-02-12 - API Exception Disclosure Pattern
**Vulnerability:** `api.php` returned raw exception messages directly to clients, exposing internal errors (including potential database/application details).
**Learning:** Error handling focused on developer visibility, but lacked a separate safe message path for external API consumers.
**Prevention:** Centralize API error responses around generic public messages and always log internal exception details server-side.

## 2026-02-12 - Signed Update Gate Is Mandatory
**Vulnerability:** Self-update akışı imza doğrulaması olmadan uzak paket uygulayabiliyordu; bu, tedarik zinciri ihlali durumunda risk oluşturuyor.
**Learning:** Update mekanizması HTTPS + host doğrulaması tek başına yeterli değil; kriptografik doğrulama ve güvenilir public key yoksa fail-closed yaklaşımı şart.
**Prevention:** `AUTO_UPDATE` varsayılanı kapalı tutulmalı, `UPDATE_REQUIRE_SIGNATURE=true` ve `UPDATE_SIGNING_PUBLIC_KEY_PEM` olmadan update uygulanmamalı.

