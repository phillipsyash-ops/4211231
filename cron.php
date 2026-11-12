<?php
// Optional: run every minute from cPanel → Cron Jobs so price alerts fire even when nobody is online:
//   * * * * * php /home/USER/your-site/cron.php >/dev/null 2>&1
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/inc/core.php';
prices();
