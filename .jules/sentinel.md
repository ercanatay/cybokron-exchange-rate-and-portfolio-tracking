## 2026-02-14 - [Critical IDOR in API]
**Vulnerability:** IDOR and Information Disclosure in `alerts` and `alerts_delete` endpoints in `api.php`. The `alerts` endpoint listed all alerts for all users, and `alerts_delete` allowed deleting any alert by ID.
**Learning:** Raw SQL queries in API endpoints often miss the user-scoping logic that is encapsulated in model classes (like `Portfolio`).
**Prevention:** Always encapsulate database logic in model classes that enforce ownership checks, or explicitly add user scoping clauses (e.g., `WHERE user_id = ?`) to raw queries when acting on user-specific resources.
