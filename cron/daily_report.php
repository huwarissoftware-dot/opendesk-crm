<?php
declare(strict_types=1);
/* Cron: daily pending summary (run at 08:30). Written to storage/backups/ and audit-logged. */
$root=dirname(__DIR__);
require $root.'/app/core.php'; require $root.'/app/db.php';
$db=db();
$rows=$db->query("SELECT reference,customer_company,type,status,pending_at,department,opened_at FROM tickets WHERE status NOT IN ('Resolved','Closed') ORDER BY opened_at")->fetchAll();
$lines=["OpenDesk CRM - Daily Pending Report - ".date('Y-m-d'), str_repeat('=',60)];
foreach ($rows as $r) $lines[]=$r['reference'].' | '.$r['customer_company'].' | '.$r['type'].' | '.$r['status'].' | Pending at: '.$r['pending_at'].' | '.$r['department'].' | Opened: '.$r['opened_at'];
$lines[]=''; $lines[]='Total open/pending: '.count($rows);
$file=$root.'/storage/backups/daily-report-'.date('Ymd').'.txt';
file_put_contents($file, implode("\n",$lines));
audit($db,'Daily pending report generated','reports',['open: '.count($rows)],['name'=>'System','email'=>'','department'=>'System']);
echo "Daily report written: $file\n";
