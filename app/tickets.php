<?php
declare(strict_types=1);
/* Ticket domain logic: reference format, classification, SLA, times. */
const COMPLAINT_CATEGORIES=['Link Down','Slow Speed / High Latency','Fiber Fault','Power Issue','Equipment Fault','Packet Loss','Intermittent Connectivity','Email Issue','Other Complaint'];
const REQUEST_CATEGORIES=['New Deployment (SOF)','Upgrade (SOF)','Termination (TOS)','Cross Connect','Shifting','Additional Service','Other Request'];
const RFO_REASONS=['Fiber Cut','Power Outage','Equipment Failure','Configuration Issue','Vendor / Upstream Issue','Planned Maintenance','Customer End Issue','Weather','Other'];
const UPDATE_TYPES=['General Update','Deployment Testing Update','Testing Passed','Go-Live / Completed','Site Survey','Installation Update','Vendor Update','Customer Update','Resolution Note'];
const SLA_TARGETS=['Critical'=>4,'High'=>8,'Medium'=>24,'Low'=>48]; // hours, defaults; editable in Settings
function ticket_type_for_category(string $cat): string {
    return in_array($cat, REQUEST_CATEGORIES, true) ? 'Request' : 'Complaint';
}
/* Reference format: <Customer Company Name>-<DDMMYYYY>-<per-customer daily sequence>
   e.g. Acme Textiles-04112026-1, that customer's 2nd ticket the same day = -2.
   The sequence is computed inside a transaction with a per-customer daily counter
   row locked FOR UPDATE, so concurrent lodges can never duplicate a reference. */
function next_ticket_reference(PDO $db, array $customer): string {
    $today=date('Y-m-d'); $stamp=date('dmY');
    $st=$db->prepare('INSERT INTO ticket_daily_counters(customer_id,day,last_seq) VALUES(?,?,1)
        ON DUPLICATE KEY UPDATE last_seq=last_seq+1');
    $st->execute([(int)$customer['id'],$today]);
    $q=$db->prepare('SELECT last_seq FROM ticket_daily_counters WHERE customer_id=? AND day=?');
    $q->execute([(int)$customer['id'],$today]);
    $seq=(int)$q->fetchColumn();
    return $customer['company'].'-'.$stamp.'-'.$seq;
}
function sla_target_hours(PDO $db, string $priority): int {
    $v=setting($db,'sla_'.strtolower($priority));
    return $v!==null ? (int)$v : (SLA_TARGETS[$priority]??24);
}
function sla_status(array $t, PDO $db): string {
    if (!$t['opened_at']) return '';
    $start=strtotime((string)$t['opened_at']);
    $end=$t['resolved_at'] ? strtotime((string)$t['resolved_at']) : time();
    $hours=($end-$start)/3600;
    return $hours <= sla_target_hours($db,(string)$t['priority']) ? 'Within SLA' : 'SLA Breached';
}
function downtime_text(array $t): string {
    if (!$t['opened_at'] || !$t['resolved_at']) return '';
    $s=strtotime((string)$t['resolved_at'])-strtotime((string)$t['opened_at']);
    return sprintf('%dh %02dm', intdiv($s,3600), intdiv($s%3600,60));
}
function validate_upload(array $file, string $kind): ?string {
    /* Images & ZIP: max 10 MB. Any other file type: max 15 MB. Always optional. */
    if (($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return null;
    if ($file['error']!==UPLOAD_ERR_OK) return 'Upload failed for '.$file['name'];
    $ext=strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $isImageZip=in_array($ext,['jpg','jpeg','png','gif','webp','zip'],true);
    $limit=($isImageZip?10:15)*1024*1024;
    if ($file['size']>$limit) return $file['name'].' exceeds the '.($isImageZip?'10 MB':'15 MB').' limit.';
    $bad=['php','phtml','phar','exe','sh','bat','js','html','htm'];
    if (in_array($ext,$bad,true)) return 'File type not allowed: '.$file['name'];
    return null;
}
function store_upload(PDO $db, array $file, int $ticketId, int $userId): void {
    $dir=dirname(__DIR__).'/storage/uploads';
    $safe=bin2hex(random_bytes(8)).'_'.preg_replace('/[^A-Za-z0-9._-]/','_', (string)$file['name']);
    move_uploaded_file((string)$file['tmp_name'], $dir.'/'.$safe);
    $st=$db->prepare('INSERT INTO attachments(ticket_id,file_name,stored_name,size_bytes,uploaded_by,created_at) VALUES(?,?,?,?,?,NOW())');
    $st->execute([$ticketId,$file['name'],$safe,(int)$file['size'],$userId]);
}
function handle_uploads(PDO $db, string $field, int $ticketId, int $userId): ?string {
    if (empty($_FILES[$field])) return null;
    $files=$_FILES[$field];
    $list=is_array($files['name'])?$files['name']:[$files['name']];
    foreach ($list as $i=>$name) {
        if ($name===''||$name===null) continue;
        $f=['name'=>$name,'type'=>$files['type'][$i]??'','tmp_name'=>$files['tmp_name'][$i]??'','error'=>$files['error'][$i]??4,'size'=>$files['size'][$i]??0];
        $err=validate_upload($f,'any');
        if ($err) return $err;
        store_upload($db,$f,$ticketId,$userId);
    }
    return null;
}
