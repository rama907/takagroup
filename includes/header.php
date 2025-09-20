<header class="header">
    <div class="header-content">
        <div class="header-left">
            <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle Menu">
                <span class="hamburger-icon">☰</span>
            </button>
            <div class="logo">
                <span class="logo-icon">✨</span>
                <span class="logo-text">Galaxy Night Club</span>
            </div>
        </div>
        <div class="header-actions">
            <div class="user-menu">
                <span class="user-name"><?= htmlspecialchars($_SESSION['name']) ?></span>
                <a href="logout.php" class="btn btn-outline btn-sm">Keluar</a>
            </div>
        </div>
    </div>
</header>