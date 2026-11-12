<?php
// Extra features that work without changing the site's own pages:
//   • swap can be turned off for a chosen user (admin → «Пользователи и кошельки»);
//   • Support: a chat button in the wallet, tickets are answered in the admin (tab «Поддержка»).
// Loaded from inc/pwa.php (the line at the end of inc/core.php).

if (defined('LOYAL_ADDONS')) return;
define('LOYAL_ADDONS', 1);
const ADDONS_VER = 2;

function addons_db(): bool {
    static $ok = null; if ($ok !== null) return $ok;
    $f = __DIR__ . '/../data/addons_v';
    if (is_file($f) && (int)file_get_contents($f) >= ADDONS_VER) return $ok = true;
    try {
        try { q('ALTER TABLE users ADD COLUMN swap_blocked TINYINT NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
        q('CREATE TABLE IF NOT EXISTS support_tickets (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            user_id INTEGER NOT NULL,
            subject VARCHAR(140) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT \'open\',
            user_unread INTEGER NOT NULL DEFAULT 0,
            admin_unread INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY st_user (user_id), KEY st_upd (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        q('CREATE TABLE IF NOT EXISTS support_messages (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            ticket_id INTEGER NOT NULL,
            sender VARCHAR(5) NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY sm_ticket (ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        q('CREATE TABLE IF NOT EXISTS linked_wallets (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            user_id INTEGER NOT NULL,
            chain VARCHAR(8) NOT NULL,
            address VARCHAR(100) NOT NULL,
            wallet VARCHAR(40) NULL,
            icon MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY lw_uniq (user_id, chain, address),
            KEY lw_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        @file_put_contents($f, (string)ADDONS_VER);
        return $ok = true;
    } catch (Throwable $e) { return $ok = false; }
}

function swap_blocked_uid(int $uid): bool {
    if (!addons_db()) return false;
    try { return (bool)q('SELECT swap_blocked FROM users WHERE id = ?', [$uid])->fetchColumn(); } catch (Throwable $e) { return false; }
}
function support_unread(int $uid): int {
    if (!addons_db()) return 0;
    try { return (int)q('SELECT COALESCE(SUM(user_unread),0) FROM support_tickets WHERE user_id = ?', [$uid])->fetchColumn(); } catch (Throwable $e) { return 0; }
}

const SWAP_BLOCK_MSG = 'Swap is unavailable for your account. Please contact support.';

// guard: a blocked user can't swap even by sending the form directly
(function () {
    if (PHP_SAPI === 'cli' || empty($_SESSION['uid'])) return;
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'swap.php' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    if (!swap_blocked_uid((int)$_SESSION['uid'])) return;
    flash(SWAP_BLOCK_MSG, 'err');
    $w = (int)($_GET['w'] ?? 0);
    redirect('swap.php' . ($w ? '?w=' . $w . '&' : '?') . 'blocked=1');
})();
