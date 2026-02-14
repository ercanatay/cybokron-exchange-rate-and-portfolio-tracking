# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.9.x   | Yes       |
| 1.8.x   | Security fixes only |
| < 1.8   | No        |

## Reporting a Vulnerability

If you discover a security vulnerability in Cybokron, please report it responsibly.

**Do NOT open a public GitHub issue for security vulnerabilities.**

Instead, please email: **security@ercanatay.com**

Include the following in your report:

- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

## Response Timeline

- **Acknowledgment:** Within 48 hours
- **Initial assessment:** Within 1 week
- **Fix release:** Within 2 weeks for critical issues, 4 weeks for others

## Scope

The following are in scope:

- SQL injection
- Cross-site scripting (XSS)
- Cross-site request forgery (CSRF) bypasses
- Server-side request forgery (SSRF)
- Authentication and authorization bypasses (IDOR)
- Local file inclusion (LFI) / Remote file inclusion (RFI)
- Insecure direct object references
- Sensitive data exposure
- Remote code execution

The following are out of scope:

- Denial of service (DoS) attacks
- Social engineering
- Issues in third-party dependencies (report to the upstream project)
- Issues requiring physical access to the server
- Self-XSS (attacks requiring the victim to paste code into their own console)

## Security Features

Cybokron includes the following security measures:

- **CSRF protection** on all state-changing operations (portfolio, alerts, admin settings)
- **Authentication enforcement** on all API write operations
- **Input validation** with allowlists for database columns, bank slugs, and currency codes
- **SSRF prevention** with HTTPS-only enforcement and `CURLOPT_PROTOCOLS` on all outbound HTTP calls
- **LFI prevention** with alphanumeric validation and `realpath()` checks on dynamic file includes
- **XSS prevention** with `htmlspecialchars()` on all user-controlled output
- **SQL injection prevention** with parameterized queries (PDO prepared statements) throughout
- **Rate limiting** on login attempts, API reads/writes, and repair endpoints
- **Security headers** (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `Content-Security-Policy`, `Strict-Transport-Security`)
- **Cloudflare Turnstile CAPTCHA** on login page (optional)
- **Session security** with `httponly`, `secure`, and `samesite` cookie flags
- **Password hashing** with `password_hash()` (bcrypt)

## Past Security Advisories

| Version | Date | Summary |
|---------|------|---------|
| 1.9.2 | 2026-02-14 | Fix unauthenticated API write access, LFI in loadBankScraper, CAGR overflow, email validation |
| 1.9.1 | 2026-02-14 | Fix IDOR on tag/group/goal APIs, SSRF via webhooks, XSS, open redirect, schema integrity |
| 1.5.5 | 2026-02-14 | Fix CSRF bypass on goals, IDOR on goal operations, stored XSS, race condition |
| 1.5.4 | 2026-02-13 | Fix IDOR in alerts API |
| 1.5.2 | 2026-02-13 | Add CSRF to login, SSL verification on webhooks |

## Acknowledgments

We appreciate responsible disclosure. Contributors who report valid security issues will be credited in the release notes (unless they prefer to remain anonymous).
