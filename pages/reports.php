<?php
declare(strict_types=1);
/* Reports & Exports: department performance, RFO, vendor data, monthly, single customer, CSV. */
function report_window(): array {
    $range=(string)($_GET['range']??'all'); $now=new DateTime();
    return match($range) {
        'month'=>[$now->format('Y-m-01 00:00:00'),$now->format('Y-m-t 23:59:59')],
        'last_month'=>[$now->modify('first day of last month')->format('Y-m-d 00:00:00'),$now->modify('last day of last month')->format('Y-m-d 23:59:59')],
        'year'=>[$now->format('Y-01-01 00:00:00'),$now->format('Y-12-31 23:59:59')],
        'custom'=>[($_GET['from']??'').' 00:00:00',($_GET['to']??'').' 23:59:59'],
        default=>['1970-01-01 00:00:00','2999-12-31 23:59:59'],
    };
}
function filtered_tickets(PDO $db): array {
    [$from,$to]=report_window(); $args=[$from,$to]; $where='opened_at BETWEEN ? AND ?';
    if (!empty($_GET['customer_id'])) { $where.=' AND customer_id=?'; $args[]=(int)$_GET['customer_id']; }
    if (!empty($_GET['department'])) { $where.=' AND department=?'; $args[]=$_GET['department']; }
    if (!empty($_GET['status'])) { $where.=' AND status=?'; $args[]=$_GET['status']; }
    $st=$db->prepare("SELECT * FROM tickets WHERE $where ORDER BY id"); $st->execute($args); return $st->fetchAll();
}
function page_reports(PDO $db): void {
    $u=require_role('Team Lead');
    $rows=filtered_tickets($db);
    layout_start($db,'Reports',$u);
    echo '<h1>Reports &amp; Export Center</h1>';
    echo '<form method="get" class="card"><input type="hidden" name="page" value="reports">';
    echo '<label>Range</label><select name="range"><option value="all">All Time</option><option value="month">This Month</option><option value="last_month">Last Month</option><option value="year">This Year</option><option value="custom">Custom</option></select>';
    echo '<label>From</label><input type="date" name="from"><label>To</label><input type="date" name="to">';
    echo '<label>Customer</label><select name="customer_id"><option value="">All customers</option>';
    foreach ($db->query('SELECT id,company FROM customers ORDER BY company') as $c) echo '<option value="'.(int)$c['id'].'">'.e($c['company']).'</option>';
    echo '</select><button type="submit">Apply</button> ';
    echo '<a class="btn secondary" href="index.php?page=report_csv&kind=tickets&'.e(http_build_query(array_diff_key($_GET,['page'=>'']))).'">Export Tickets CSV</a> ';
    echo '<a class="btn secondary" href="index.php?page=report_csv&kind=rfo">Export RFO CSV</a> ';
    echo '<a class="btn secondary" href="index.php?page=report_csv&kind=remarks">Export Remarks CSV</a> ';
    echo '<a class="btn secondary" href="index.php?page=report_csv&kind=audit">Export Audit Log CSV</a></form>';
    // Department Performance - per department: Received = Complaints + Requests, Resolved, Open, OTS-at-own-end, avg resolution, overdue
    $depts=[]; foreach ($rows as $t) { $d=(string)($t['department']??'Unassigned'); $depts[$d][]=$t; }
    echo '<div class="card"><h2>Department Performance</h2><table><tr><th>Department</th><th>Received</th><th>Complaints</th><th>Requests</th><th>Resolved</th><th>Open / Pending</th><th>Resolved at Own End (OTS)</th><th>Avg Resolution (h)</th><th>Overdue</th></tr>';
    $tot=['r'=>0,'c'=>0,'q'=>0,'res'=>0,'open'=>0,'own'=>0];
    foreach ($depts as $d=>$ts) {
        $c=count(array_filter($ts,fn($t)=>$t['type']==='Complaint')); $q=count($ts)-$c;
        $res=array_filter($ts,fn($t)=>$t['resolved_at']); $open=count($ts)-count(array_filter($ts,fn($t)=>in_array($t['status'],['Resolved','Closed'],true)));
        $own=count(array_filter($ts,fn($t)=>$t['resolved_at'] && $t['resolved_by_department']===$d));
        $avg=''; if ($res) { $sum=0; foreach ($res as $t) $sum+=strtotime((string)$t['resolved_at'])-strtotime((string)$t['opened_at']); $avg=number_format($sum/count($res)/3600,1); }
        $over=count(array_filter($ts,fn($t)=>!in_array($t['status'],['Resolved','Closed'],true) && sla_status($t,$db)==='SLA Breached'));
        echo '<tr><td>'.e($d).'</td><td>'.count($ts).'</td><td>'.$c.'</td><td>'.$q.'</td><td>'.count($res).'</td><td>'.$open.'</td><td>'.$own.'</td><td>'.$avg.'</td><td>'.$over.'</td></tr>';
        $tot['r']+=count($ts); $tot['c']+=$c; $tot['q']+=$q; $tot['res']+=count($res); $tot['open']+=$open; $tot['own']+=$own;
    }
    echo '<tr><th>Totals</th><th>'.$tot['r'].'</th><th>'.$tot['c'].'</th><th>'.$tot['q'].'</th><th>'.$tot['res'].'</th><th>'.$tot['open'].'</th><th>'.$tot['own'].'</th><th></th><th></th></tr></table>';
    echo '<p class="muted">Check: Received = Complaints + Requests in every row. "Resolved at Own End" is shown for Online Technical Support (OTS): tickets OTS resolved without sending them to another team.</p></div>';
    // RFO section
    $rfo=[]; foreach ($rows as $t) if ($t['rfo_reason']) $rfo[$t['rfo_reason']][]=$t;
    echo '<div class="card"><h2>RFO - Reason For Outage</h2><table><tr><th>Reason</th><th>Outages</th><th>Share</th><th>Avg Downtime (h)</th></tr>';
    $totRfo=max(1,array_sum(array_map('count',$rfo)));
    foreach ($rfo as $reason=>$ts) { $sum=0;$n=0; foreach ($ts as $t) if ($t['resolved_at']) { $sum+=strtotime((string)$t['resolved_at'])-strtotime((string)$t['opened_at']); $n++; }
        echo '<tr><td>'.e($reason).'</td><td>'.count($ts).'</td><td>'.number_format(count($ts)/$totRfo*100,1).'%</td><td>'.($n?number_format($sum/$n/3600,1):'').'</td></tr>'; }
    echo '</table></div>';
    // Vendor charts data (rendered as tables + simple bars; the tables are the verification source)
    $vend=[]; foreach ($rows as $t) if ($t['vendor_id']) $vend[(int)$t['vendor_id']][]=$t;
    $vnames=[]; foreach ($db->query('SELECT id,name FROM vendors') as $v) $vnames[(int)$v['id']]=$v['name'];
    echo '<div class="card"><h2>Complaints by Vendor</h2>';
    uasort($vend, fn($a,$b)=>count($b)<=>count($a));
    foreach ($vend as $vid=>$ts) { $n=count($ts); echo '<p>'.e($vnames[$vid]??('Vendor '.$vid)).' <span style="display:inline-block;background:#0f6cbd;height:14px;width:'.min(300,$n*30).'px"></span> '.$n.'</p>'; }
    echo '<h2>Vendor Resolution Delay</h2><table><tr><th>Vendor</th><th>Avg Resolution (h)</th><th>Tickets Late vs ETR</th></tr>';
    foreach ($vend as $vid=>$ts) { $sum=0;$n=0;$late=0; foreach ($ts as $t) { if ($t['resolved_at']) { $sum+=strtotime((string)$t['resolved_at'])-strtotime((string)$t['opened_at']); $n++; if ($t['etr'] && strtotime((string)$t['resolved_at'])>strtotime((string)$t['etr'])) $late++; } }
        echo '<tr><td>'.e($vnames[$vid]??('Vendor '.$vid)).'</td><td>'.($n?number_format($sum/$n/3600,1):'').'</td><td>'.$late.'</td></tr>'; }
    echo '</table></div>';
    // Monthly report
    echo '<div class="card"><h2>Monthly Report (last 12 months)</h2><table><tr><th>Month</th><th>Received</th><th>Complaints</th><th>Requests</th><th>Resolved</th></tr>';
    for ($i=11;$i>=0;$i--) { $m=(new DateTime())->modify("-$i months");
        $q=$db->prepare('SELECT type, resolved_at FROM tickets WHERE opened_at BETWEEN ? AND ?'); $q->execute([$m->format('Y-m-01 00:00:00'),$m->format('Y-m-t 23:59:59')]); $ms=$q->fetchAll();
        echo '<tr><td>'.$m->format('Y-m').'</td><td>'.count($ms).'</td><td>'.count(array_filter($ms,fn($t)=>$t['type']==='Complaint')).'</td><td>'.count(array_filter($ms,fn($t)=>$t['type']==='Request')).'</td><td>'.count(array_filter($ms,fn($t)=>$t['resolved_at'])).'</td></tr>'; }
    echo '</table></div>';
    // Single customer
    echo '<div class="card"><h2>Single Customer Report</h2><form method="get"><input type="hidden" name="page" value="customer_report"><label>Customer</label><select name="customer_id">';
    foreach ($db->query('SELECT id,company FROM customers ORDER BY company') as $c) echo '<option value="'.(int)$c['id'].'">'.e($c['company']).'</option>';
    echo '</select><button type="submit">Open Printable Report</button></form></div>';
    layout_end();
}
function page_customer_report(PDO $db): void {
    require_role('Team Lead');
    $cid=(int)($_GET['customer_id']??0);
    $q=$db->prepare('SELECT * FROM customers WHERE id=?'); $q->execute([$cid]); $cust=$q->fetch();
    print_start($db,'Customer Report');
    if (!$cust) { echo '<p>Customer not found.</p>'; print_end(); return; }
    audit($db,'Single Customer Report viewed: '.$cust['company'],'reports',['customer_report']);
    echo '<div class="card"><h1>'.e($cust['company']).'</h1><p>'.e($cust['contact_person']).' &middot; '.e($cust['email']).' &middot; '.e($cust['service']).'</p>';
    $st=$db->prepare('SELECT * FROM tickets WHERE customer_id=? ORDER BY id'); $st->execute([$cid]);
    echo '<table><tr><th>Reference</th><th>Type</th><th>Status</th><th>Opened</th><th>Opened By</th><th>Resolved</th><th>Resolved By</th><th>Closed</th><th>Closed By</th><th>Downtime</th><th>RFO</th></tr>';
    foreach ($st as $t) echo '<tr><td>'.e($t['reference']).'</td><td>'.e($t['type']).'</td><td>'.e($t['status']).'</td><td>'.e($t['opened_at']).'</td><td>'.e($t['opened_by']).'</td><td>'.e($t['resolved_at']).'</td><td>'.e($t['resolved_by']).'</td><td>'.e($t['closed_at']).'</td><td>'.e($t['closed_by']).'</td><td>'.e(downtime_text($t)).'</td><td>'.e($t['rfo_reason']).'</td></tr>';
    echo '</table></div>';
    print_end();
}
function page_report_csv(PDO $db): void {
    $u=require_role('Team Lead'); $kind=(string)($_GET['kind']??'tickets');
    audit($db,'CSV export: '.$kind,'reports',['export']);
    if ($kind==='rfo') {
        $rows=$db->query("SELECT * FROM tickets WHERE rfo_reason IS NOT NULL AND rfo_reason<>'' ORDER BY id")->fetchAll();
        $out=[]; foreach ($rows as $t) $out[]=[$t['reference'],$t['customer_company'],$t['rfo_reason'],$t['rca'],$t['rfo_by'],$t['rfo_department'],$t['rfo_at'],downtime_text($t)];
        send_csv('opendesk-rfo-'.date('Ymd').'.csv',['Reference','Customer','RFO Reason','RCA','RFO By','Department','RFO At','Downtime'],$out);
    } elseif ($kind==='remarks') {
        $rows=$db->query('SELECT r.*, t.reference FROM remarks r JOIN tickets t ON t.id=r.ticket_id ORDER BY r.id')->fetchAll();
        $out=[]; foreach ($rows as $r) $out[]=[$r['reference'],$r['update_type'],$r['author_name'],$r['author_department'],$r['created_at'],$r['body']];
        send_csv('opendesk-remarks-'.date('Ymd').'.csv',['Reference','Update Type','Author','Department','At','Remark'],$out);
    } elseif ($kind==='audit') {
        $rows=$db->query('SELECT * FROM audit_log ORDER BY id')->fetchAll();
        $out=[]; foreach ($rows as $r) $out[]=[$r['created_at'],$r['actor_name'],$r['actor_email'],$r['actor_department'],$r['action'],$r['target'],$r['changed_fields']];
        send_csv('opendesk-audit-log-'.date('Ymd').'.csv',['At','Actor','Email','Department','Action','Target','Changed Fields'],$out);
    } else {
        $rows=filtered_tickets($db);
        $vnames=[]; foreach ($db->query('SELECT id,name FROM vendors') as $v) $vnames[(int)$v['id']]=$v['name'];
        $out=[]; foreach ($rows as $t) $out[]=[$t['reference'],$t['type'],$t['category'],$t['priority'],$t['customer_company'],$t['customer_email'],$t['address'],$t['service'],$t['vendor_id']?($vnames[(int)$t['vendor_id']]??''):'' ,$t['status'],$t['pending_at'],$t['department'],$t['opened_at'],$t['opened_by'],$t['resolved_at'],$t['resolved_by'],$t['closed_at'],$t['closed_by'],$t['etr'],downtime_text($t),sla_status($t,$db),$t['rfo_reason'],$t['rca'],$t['confirmation_status']];
        send_csv('opendesk-tickets-full-isp-record-'.date('Ymd').'.csv',['Reference','Type','Category','Priority','Customer','Customer Email','Address','Service','Vendor','Status','Pending At','Department','Opened At','Opened By','Resolved At','Resolved By','Closed At','Closed By','ETR','Downtime','SLA Status','RFO Reason','RCA','Confirmation Call'],$out);
    }
}
