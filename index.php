<?php
declare(strict_types=1);
/* Entry point: send visitors to the application (or the installer on first run). */
if (is_file(__DIR__.'/config/config.php')) { header('Location: public/index.php'); }
else { header('Location: install/'); }
exit;
