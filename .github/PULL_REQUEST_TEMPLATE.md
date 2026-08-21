## Summary

<!-- What does this PR change, and why? -->

## Related Issue

<!-- e.g. "Closes #123", or "N/A" -->

## Type of Change

- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation
- [ ] Refactor / cleanup
- [ ] Database migration included

## Test Plan

<!-- How did you verify this? Commands run, scenarios covered, screenshots for UI changes. -->

```bash
php tests/run.php
find . -name "*.php" -not -path "./vendor/*" | xargs -n1 php -l
```

## Checklist

- [ ] `php tests/run.php` passes
- [ ] `php -l` reports no syntax errors on changed files
- [ ] New/changed strings use the `t()` helper with keys added to all 5 locale files (if user-facing)
- [ ] Database changes ship as a new file in `database/migrations/` (not edits to `database/database.sql` alone)
- [ ] No secrets, credentials, or personal server details in the diff
