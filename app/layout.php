<?php
declare(strict_types=1);
/* Shared page layout. The background watermark logo (if uploaded by the Owner)
   renders behind every screen at ~10% opacity, blurred. The footer credit comes
   ONLY from app_footer_credit() in app/core.php - hard-coded, not a setting. */
function layout_start(PDO $db, string $title, ?array $user): void {
    $org=org_name($db);
    $logo=setting($db,'logo_path'); $wm=setting($db,'watermark_path');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.e($title).' - '.e($org).'</title>';
    echo '<link rel="stylesheet" href="assets/style.css">';
    echo '<link rel="manifest" href="manifest.php">';
    echo '<meta name="theme-color" content="#0f2a4a"></head><body>';
    if ($wm) echo '<div class="watermark"><img src="'.e($wm).'" alt=""></div>';
    echo '<div class="app">';
    if ($user) {
        echo '<header class="topbar">';
        if ($logo) echo '<img class="logo" src="'.e($logo).'" alt="">';
        echo '<span class="org">'.e($org).'</span>';
        echo '<nav>';
        foreach (['dashboard'=>'Dashboard','tickets'=>'Tickets','customers'=>'Customers','vendors'=>'Vendors','colo'=>'Co-Location','stock'=>'Stock Room','kb'=>'Knowledge Base','reports'=>'Reports','activity'=>'Activity','tv'=>'TV Board'] as $p=>$label) {
            echo '<a href="index.php?page='.$p.'">'.e($label).'</a>';
        }
        if (can_manage_users($user)) echo '<a href="index.php?page=users">Users</a>';
        if (is_owner($user) || ($user['role']??'')==='Admin') echo '<a href="index.php?page=settings">Settings</a>';
        echo '</nav>';
        $unread=0;
        try { $st=$db->prepare('SELECT COUNT(*) FROM alerts WHERE user_id=? AND is_read=0'); $st->execute([(int)$user['id']]); $unread=(int)$st->fetchColumn(); } catch (Throwable) {}
        echo '<span class="bell" title="Alerts">&#128276;'.($unread?'<b>'.$unread.'</b>':'').'</span>';
        echo '<span class="who">'.e($user['name']).' ('.e($user['designation']??$user['role']).') &middot; '.e($user['department']??'').'</span>';
        echo '<a class="logout" href="index.php?page=logout">Logout</a></header>';
    }
    foreach (flashes() as [$t,$m]) echo '<div class="flash '.e($t).'">'.e($m).'</div>';
    echo '<main>';
}
function layout_end(): void {
    echo '</main>';
    echo app_footer_credit(); // STRICT: hard-coded credit on every page. Do not remove. See LICENSE.
    echo '</div><script src="assets/app.js"></script></body></html>';
}
function print_start(PDO $db, string $title): void {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'.e($title).'</title><link rel="stylesheet" href="assets/style.css"></head><body class="print">';
}
function print_end(): void { echo app_footer_credit().'</body></html>'; }
