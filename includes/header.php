<?php
/**
 * Shared header component — Navigation Bar
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
$_isAdmin = Auth::check() && Auth::isAdmin();
?>
<header class="header">
    <div class="container header-container">
        <!-- Brand -->
        <a href="index.php" class="header-brand" aria-label="<?= APP_NAME ?>">
            <span class="header-brand-logo">C</span>
            <span class="header-brand-text">Cybokron</span>
        </a>

        <!-- Desktop nav links (center) -->
        <nav class="header-nav" id="header-nav" role="navigation" aria-label="<?= htmlspecialchars(t('nav.main')) ?>">
            <div class="header-nav-links">
                <a href="index.php" class="header-nav-link <?= $_headerActivePage === 'rates' ? 'active' : '' ?>"
                    <?= $_headerActivePage === 'rates' ? ' aria-current="page"' : '' ?>>
                    <?= t('nav.rates') ?>
                </a>
                <a href="portfolio.php" class="header-nav-link <?= $_headerActivePage === 'portfolio' ? 'active' : '' ?>"
                    <?= $_headerActivePage === 'portfolio' ? ' aria-current="page"' : '' ?>>
                    <?= t('nav.portfolio') ?>
                </a>
                <?php if ($_isAdmin): ?>
                    <a href="observability.php" class="header-nav-link <?= $_headerActivePage === 'observability' ? 'active' : '' ?>"
                        <?= $_headerActivePage === 'observability' ? ' aria-current="page"' : '' ?>>
                        <?= t('observability.title') ?>
                    </a>
                    <a href="openrouter.php" class="header-nav-link <?= $_headerActivePage === 'openrouter' ? 'active' : '' ?>"
                        <?= $_headerActivePage === 'openrouter' ? ' aria-current="page"' : '' ?>>
                        AI
                    </a>
                    <a href="admin.php" class="header-nav-link <?= $_headerActivePage === 'admin' ? 'active' : '' ?>"
                        <?= $_headerActivePage === 'admin' ? ' aria-current="page"' : '' ?>>
                        <?= t('admin.title') ?>
                    </a>
                <?php endif; ?>
            </div>
        </nav>

        <!-- Right actions -->
        <div class="header-actions">
            <!-- Language switcher dropdown -->
            <div class="header-lang-dropdown" id="header-lang-dropdown">
                <button type="button" class="header-action-btn header-lang-trigger" id="header-lang-trigger"
                    aria-expanded="false" aria-haspopup="true"
                    title="<?= htmlspecialchars(t('nav.theme_toggle')) ?>">
                    <?= strtoupper($currentLocale) ?>
                </button>
                <div class="header-lang-menu" id="header-lang-menu" role="menu">
                    <?php foreach ($availableLocales as $locale): ?>
                        <?php
                        $localeName = t('nav.language_name.' . $locale);
                        if (str_contains($localeName, 'nav.language_name.')) {
                            $localeName = strtoupper($locale);
                        }
                        ?>
                        <a href="<?= htmlspecialchars(buildLocaleUrl($locale)) ?>"
                            class="header-lang-option <?= $currentLocale === $locale ? 'active' : '' ?>"
                            lang="<?= htmlspecialchars($locale) ?>" hreflang="<?= htmlspecialchars($locale) ?>"
                            role="menuitem"
                            aria-label="<?= htmlspecialchars(t('nav.language_switch_to', ['language' => $localeName])) ?>">
                            <span class="lang-code"><?= strtoupper($locale) ?></span>
                            <span class="lang-name"><?= htmlspecialchars($localeName) ?></span>
                            <?php if ($currentLocale === $locale): ?>
                                <span class="lang-check">✓</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Theme toggle -->
            <button type="button" id="theme-toggle" class="header-action-btn"
                aria-label="<?= t('nav.theme_toggle') ?>" title="<?= t('nav.theme_toggle') ?>"
                data-label-light="<?= htmlspecialchars(t('theme.switch_to_light')) ?>"
                data-label-dark="<?= htmlspecialchars(t('theme.switch_to_dark')) ?>">
                <span class="theme-icon">🌙</span>
            </button>

            <!-- Auth -->
            <?php if (Auth::check()): ?>
                <form method="POST" action="logout.php" class="header-auth-form" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                    <button type="submit" class="header-auth-btn header-auth-btn--logout" title="<?= t('nav.logout') ?>">
                        <?= t('nav.logout') ?>
                    </button>
                </form>
            <?php else: ?>
                <a href="login.php" class="header-auth-btn header-auth-btn--login" title="<?= t('nav.login') ?>">
                    <?= t('nav.login') ?>
                </a>
            <?php endif; ?>
        </div>

        <!-- Mobile menu toggle -->
        <button type="button" class="header-menu-toggle" id="header-menu-toggle"
            aria-label="<?= htmlspecialchars(t('nav.menu')) ?>" aria-expanded="false" aria-controls="header-mobile-menu">
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
        </button>
    </div>

    <!-- Mobile menu overlay -->
    <div class="header-mobile-menu" id="header-mobile-menu">
        <div class="mobile-nav-links">
            <a href="index.php" class="mobile-nav-link <?= $_headerActivePage === 'rates' ? 'active' : '' ?>">
                <span class="mobile-nav-icon">📊</span> <?= t('nav.rates') ?>
            </a>
            <a href="portfolio.php" class="mobile-nav-link <?= $_headerActivePage === 'portfolio' ? 'active' : '' ?>">
                <span class="mobile-nav-icon">💼</span> <?= t('nav.portfolio') ?>
            </a>
            <?php if ($_isAdmin): ?>
                <a href="observability.php" class="mobile-nav-link <?= $_headerActivePage === 'observability' ? 'active' : '' ?>">
                    <span class="mobile-nav-icon">🔍</span> <?= t('observability.title') ?>
                </a>
                <a href="openrouter.php" class="mobile-nav-link <?= $_headerActivePage === 'openrouter' ? 'active' : '' ?>">
                    <span class="mobile-nav-icon">🤖</span> AI
                </a>
                <a href="admin.php" class="mobile-nav-link <?= $_headerActivePage === 'admin' ? 'active' : '' ?>">
                    <span class="mobile-nav-icon">⚙️</span> <?= t('admin.title') ?>
                </a>
            <?php endif; ?>
        </div>
        <div class="mobile-nav-footer">
            <div class="mobile-lang-row">
                <?php foreach ($availableLocales as $locale): ?>
                    <a href="<?= htmlspecialchars(buildLocaleUrl($locale)) ?>"
                        class="mobile-lang-btn <?= $currentLocale === $locale ? 'active' : '' ?>"
                        lang="<?= htmlspecialchars($locale) ?>"><?= strtoupper($locale) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="mobile-actions-row">
                <button type="button" id="theme-toggle-mobile" class="mobile-action-btn"
                    data-label-light="<?= htmlspecialchars(t('theme.switch_to_light')) ?>"
                    data-label-dark="<?= htmlspecialchars(t('theme.switch_to_dark')) ?>">
                    <span class="theme-icon">🌙</span> <?= t('nav.theme_toggle') ?>
                </button>
                <?php if (Auth::check()): ?>
                    <form method="POST" action="logout.php" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                        <button type="submit" class="mobile-action-btn mobile-action-btn--danger">
                            <?= t('nav.logout') ?>
                        </button>
                    </form>
                <?php else: ?>
                    <a href="login.php" class="mobile-action-btn"><?= t('nav.login') ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>
<script nonce="<?= getCspNonce() ?>">
(function () {
    // Mobile menu toggle
    var btn = document.getElementById('header-menu-toggle');
    var menu = document.getElementById('header-mobile-menu');
    if (!btn || !menu) return;
    btn.addEventListener('click', function () {
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', String(!expanded));
        menu.classList.toggle('open');
        btn.classList.toggle('open');
        document.body.classList.toggle('menu-open');
    });

    // Language dropdown
    var langTrigger = document.getElementById('header-lang-trigger');
    var langMenu = document.getElementById('header-lang-menu');
    var langDropdown = document.getElementById('header-lang-dropdown');
    if (langTrigger && langMenu) {
        langTrigger.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = langDropdown.classList.toggle('open');
            langTrigger.setAttribute('aria-expanded', String(open));
        });
        document.addEventListener('click', function (e) {
            if (!langDropdown.contains(e.target)) {
                langDropdown.classList.remove('open');
                langTrigger.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // Sync mobile theme toggle with desktop
    var mobileThemeBtn = document.getElementById('theme-toggle-mobile');
    if (mobileThemeBtn) {
        mobileThemeBtn.addEventListener('click', function () {
            var desktopBtn = document.getElementById('theme-toggle');
            if (desktopBtn) desktopBtn.click();
        });
    }
})();
</script>
<script src="assets/js/theme.js" defer></script>
