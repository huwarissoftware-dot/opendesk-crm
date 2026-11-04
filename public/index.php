<?php
declare(strict_types=1);
/* OpenDesk CRM - front controller. Target runtime: PHP 8.5.12 (latest patched). */
$root=dirname(__DIR__);
$cfgFile=$root.'/config/config.php';
if (!is_file($cfgFile)) { header('Location: ../install/'); exit; }
$cfg=require $cfgFile;
require $root.'/app/core.php';
require $root.'/app/db.php';
require $root.'/app/auth.php';
require $root.'/app/tickets.php';
require $root.'/app/mailer.php';
require $root.'/app/layout.php';
session_boot($cfg);
$db=db();
// Update-due check (also done by cron): flag tickets whose next_update_due has passed.
try {
    $due=$db->query("SELECT id, reference, assignee_id FROM tickets WHERE next_update_due IS NOT NULL AND next_update_due<NOW() AND status NOT IN ('Resolved','Closed')")->fetchAll();
    foreach ($due as $d) {
        if ($d['assignee_id']) {
            $chk=$db->prepare('SELECT COUNT(*) FROM alerts WHERE user_id=? AND ticket_id=? AND is_read=0'); $chk->execute([(int)$d['assignee_id'],(int)$d['id']]);
            if ((int)$chk->fetchColumn()===0) $db->prepare('INSERT INTO alerts(user_id,message,ticket_id) VALUES(?,?,?)')->execute([(int)$d['assignee_id'],'UPDATE DUE - Take update now: '.$d['reference'],(int)$d['id']]);
        }
    }
} catch (Throwable) {}
$page=(string)($_GET['page']??'dashboard');
$public=['login','setup_owner','portal','monitoring'];
if (!current_user() && !in_array($page,$public,true)) $page='login';
switch ($page) {
    case 'setup_owner': require $root.'/pages/auth.php'; page_setup_owner($db); break;
    case 'login': require $root.'/pages/auth.php'; page_login($db); break;
    case 'logout': require $root.'/pages/auth.php'; page_logout($db); break;
    case 'change_password': require $root.'/pages/auth.php'; page_change_password($db); break;
    case 'portal': require $root.'/pages/ticket_view.php'; page_portal($db); break;
    case 'monitoring': require $root.'/pages/monitoring.php'; page_monitoring($db); break;
    case 'dashboard': require $root.'/pages/dashboard.php'; page_dashboard($db); break;
    case 'activity': require $root.'/pages/dashboard.php'; page_activity($db); break;
    case 'tv': require $root.'/pages/dashboard.php'; page_tv($db); break;
    case 'tickets': require $root.'/pages/tickets.php'; page_tickets($db); break;
    case 'ticket_new': require $root.'/pages/tickets.php'; page_ticket_new($db); break;
    case 'ticket': require $root.'/pages/ticket_view.php'; page_ticket($db); break;
    case 'customers': require $root.'/pages/directory.php'; page_customers($db); break;
    case 'vendors': require $root.'/pages/directory.php'; page_vendors($db); break;
    case 'import': require $root.'/pages/directory.php'; page_import($db); break;
    case 'export': require $root.'/pages/directory.php'; page_export($db); break;
    case 'template': require $root.'/pages/directory.php'; page_template($db); break;
    case 'colo': require $root.'/pages/misc.php'; page_colo($db); break;
    case 'stock': require $root.'/pages/misc.php'; page_stock($db); break;
    case 'kb': require $root.'/pages/misc.php'; page_kb($db); break;
    case 'users': require $root.'/pages/admin.php'; page_users($db); break;
    case 'org': require $root.'/pages/admin.php'; page_org($db); break;
    case 'settings': require $root.'/pages/settings.php'; page_settings($db); break;
    case 'backup': require $root.'/pages/settings.php'; page_backup($db); break;
    case 'reports': require $root.'/pages/reports.php'; page_reports($db); break;
    case 'customer_report': require $root.'/pages/reports.php'; page_customer_report($db); break;
    case 'report_csv': require $root.'/pages/reports.php'; page_report_csv($db); break;
    default: redirect('index.php?page=dashboard');
}
