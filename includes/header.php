<?php
/**
 * Shared header component — Premium Navigation Bar
 * 
 * Required variables before include:
 *   $currentLocale     — Current locale code (e.g. 'tr', 'en')
 *   $activePage        — Active page identifier: 'rates', 'portfolio', 'observability', 'admin'
 * 
 * Optional:
 *   $availableLocales  — Array of locale codes (defaults to getAvailableLocales())
 */

if (!isset($availableLocales)) {
    $availableLocales = getAvailableLocales();
}
$_headerActivePage = $activePage ?? '';
?>
<header class="header">
    <div class="container">
        <a href="index.php" class="header-brand" aria-label="<?= APP_NAME ?>">
            <span class="header-brand-icon">💱</span>
            <span class="header-brand-text">
                <?= APP_NAME ?>
            </span>
        </a>

        <!-- Mobile: Auth button visible outside hamburger -->
        <?php if (Auth::check()): ?>
            <form method="POST" action="logout.php" class="header-auth-form header-auth-form--mobile" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <button type="submit" class="header-auth-btn header-auth-btn--logout header-auth-btn--mobile" title="<?= t('nav.logout') ?>">
                    <span class="auth-icon">🚪</span>
                    <span class="auth-text"><?= t('nav.logout') ?></span>
                </button>
            </form>
        <?php else: ?>
            <a href="login.php" class="header-auth-btn header-auth-btn--login header-auth-btn--mobile" title="<?= t('nav.login') ?>">
                <span class="auth-icon">🔑</span>
                <span class="auth-text"><?= t('nav.login') ?></span>
            </a>
        <?php endif; ?>

        <!-- Mobile menu toggle -->
        <button type="button" class="header-menu-toggle" id="header-menu-toggle" aria-label="<?= htmlspecialchars(t('nav.menu')) ?>" aria-expanded="false"
            aria-controls="header-nav">
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
        </button>

        <nav class="header-nav" id="header-nav" role="navigation"
            aria-label="<?= htmlspecialchars(t('nav.main')) ?>">
            <div class="header-nav-links">
                <a href="index.php" class="header-nav-link <?= $_headerActivePage === 'rates' ? 'active' : '' ?>"
                    <?= $_headerActivePage === 'rates' ? ' aria-current="page"' : '' ?>>
                    <span class="nav-icon">📊</span>
                    <?= t('nav.rates') ?>
                </a>
                <a href="portfolio.php"
                    class="header-nav-link <?= $_headerActivePage === 'portfolio' ? 'active' : '' ?>"
                    <?= $_headerActivePage === 'portfolio' ? ' aria-current="page"' : '' ?>>
                    <span class="nav-icon">💼</span>
                    <?= t('nav.portfolio') ?>
                </a>
                <?php if (Auth::check() && Auth::isAdmin()): ?>
                    <a href="observability.php"
                        class="header-nav-link <?= $_headerActivePage === 'observability' ? 'active' : '' ?>"
                        <?= $_headerActivePage === 'observability' ? ' aria-current="page"' : '' ?>>
                        <span class="nav-icon">🔍</span>
                        <?= t('observability.title') ?>
                    </a>
                    <a href="openrouter.php"
                        class="header-nav-link <?= $_headerActivePage === 'openrouter' ? 'active' : '' ?>"
                        <?= $_headerActivePage === 'openrouter' ? ' aria-current="page"' : '' ?>>
                        <span class="nav-icon">🤖</span>
                        <?= t('nav.openrouter') ?>
                    </a>
                    <a href="admin.php" class="header-nav-link <?= $_headerActivePage === 'admin' ? 'active' : '' ?>"
                        <?= $_headerActivePage === 'admin' ? ' aria-current="page"' : '' ?>>
                        <span class="nav-icon">⚙️</span>
                        <?= t('admin.title') ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="header-nav-actions">
                <!-- Theme toggle -->
                <button type="button" id="theme-toggle" class="header-action-btn"
                    aria-label="<?= t('nav.theme_toggle') ?>" title="<?= t('nav.theme_toggle') ?>"
                    data-label-light="<?= htmlspecialchars(t('theme.switch_to_light')) ?>"
                    data-label-dark="<?= htmlspecialchars(t('theme.switch_to_dark')) ?>">
                    <span class="theme-icon">🌙</span>
                </button>

                <!-- Language switcher -->
                <div class="header-lang-group">
                    <?php foreach ($availableLocales as $locale): ?>
                        <?php
                        $localeName = t('nav.language_name.' . $locale);
                        if (str_contains($localeName, 'nav.language_name.')) {
                            $localeName = strtoupper($locale);
                        }
                        ?>
                        <a href="<?= htmlspecialchars(buildLocaleUrl($locale)) ?>"
                            class="header-lang-btn <?= $currentLocale === $locale ? 'active' : '' ?>"
                            lang="<?= htmlspecialchars($locale) ?>" hreflang="<?= htmlspecialchars($locale) ?>"
                            aria-label="<?= htmlspecialchars(t('nav.language_switch_to', ['language' => $localeName])) ?>"
                            title="<?= htmlspecialchars(t('nav.language_switch_to', ['language' => $localeName])) ?>"
                            <?= $currentLocale === $locale ? 'aria-current="page"' : '' ?>
                            >
                            <?= htmlspecialchars(strtoupper($locale)) ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Auth -->
                <?php if (Auth::check()): ?>
                    <form method="POST" action="logout.php" class="header-auth-form" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                        <button type="submit" class="header-auth-btn header-auth-btn--logout" title="<?= t('nav.logout') ?>">
                            <span class="auth-icon">🚪</span>
                            <span class="auth-text">
                                <?= t('nav.logout') ?>
                            </span>
                        </button>
                    </form>
                <?php else: ?>
                    <a href="login.php" class="header-auth-btn header-auth-btn--login" title="<?= t('nav.login') ?>">
                        <span class="auth-icon">🔑</span>
                        <span class="auth-text">
                            <?= t('nav.login') ?>
                        </span>
                    </a>
                <?php endif; ?>
            </div>
        </nav>
    </div>
</header>
<script>
    // Mobile menu toggle
    (function () {
        var btn = document.getElementById('header-menu-toggle');
        var nav = document.getElementById('header-nav');
        if (!btn || !nav) return;
        btn.addEventListener('click', function () {
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', String(!expanded));
            nav.classList.toggle('open');
            btn.classList.toggle('open');
        });
    })();
</script>
<script src="assets/js/theme.js" defer></script>