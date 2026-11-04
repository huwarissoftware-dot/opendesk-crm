<?php
declare(strict_types=1);
/* Cron: scheduled backup (same format as Settings > Create Backup Now). */
$root=dirname(__DIR__);
require $root.'/app/core.php'; require $root.'/app/db.php';
$db=db();
$file=$root.'/storage/backups/backup-'.date('Ymd-His').'.sql';
$dump="-- OpenDesk CRM scheduled backup ".date('c')."\n";
foreach (['users','customers','vendors','tickets','remarks','attachments','approvals','audit_log','departments','designations','settings','colo_sites','colo_visits','stock_items','kb_articles'] as $tbl) {
    foreach ($db->query("SELECT * FROM $tbl") as $row) {
        $vals=array_map(fn($v)=>$v===null?'NULL':$db->quote((string)$v), array_values($row));
        $dump.="INSERT INTO $tbl (`".implode('`,`',array_keys($row))."`) VALUES (".implode(',',$vals).");\n";
    }
}
file_put_contents($file,$dump);
audit($db,'Backup created (scheduled)','system',['backup'],['name'=>'System','email'=>'','department'=>'System']);
echo "Backup written: $file\n";
