<?php
declare(strict_types=1);
/* PWA manifest - makes the CRM installable on Android ("Add to Home screen"). */
header('Content-Type: application/json');
$root=dirname(__DIR__); $name='OpenDesk CRM';
$cfgFile=$root.'/config/config.php';
if (is_file($cfgFile)) {
    require $root.'/app/core.php'; require $root.'/app/db.php';
    try { $name=org_name(db()); } catch (Throwable) {}
}
echo json_encode([
  'name'=>$name, 'short_name'=>'CRM', 'start_url'=>'index.php', 'display'=>'standalone',
  'background_color'=>'#ffffff', 'theme_color'=>'#0f2a4a',
  'icons'=>[
    ['src'=>'assets/icon-192.png','sizes'=>'192x192','type'=>'image/png'],
    ['src'=>'assets/icon-512.png','sizes'=>'512x512','type'=>'image/png'],
  ],
]);
