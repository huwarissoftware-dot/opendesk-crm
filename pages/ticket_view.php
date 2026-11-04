<?php
declare(strict_types=1);
/* Ticket detail: times, confirmation call, timeline, update/forward/resolve/close,
   approvals, vendor escalation panel, attachments, portal link, welcome email. */
function get_ticket(PDO $db, int $id): array {
    $st=$db->prepare('SELECT * FROM tickets WHERE id=?'); $st->execute([$id]);
    $t=$st->fetch(); if (!$t) { http_response_code(404); exit('Ticket not found.'); } return $t;
}
function add_remark(PDO $db, int $tid, string $body, string $type, array $u, bool $call=false): void {
    $db->prepare('INSERT INTO remarks(ticket_id,body,update_type,author_name,author_department,is_call_log) VALUES(?,?,?,?,?,?)')
       ->execute([$tid,$body,$type,$u['name'],$u['department']??'', $call?1:0]);
}
function page_ticket(PDO $db): void {
    $u=require_login();
    $id=(int)($_GET['id']??0); $t=get_ticket($db,$id);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        csrf_check(); $act=$_POST['act']??'';
        if ($act==='remark' && ($u['role']??'')!=='Viewer') {
            $body=trim($_POST['body']??''); $type=(string)($_POST['update_type']??'General Update');
            if ($body!=='') {
                add_remark($db,$id,$body,$type,$u);
                $aerr=handle_uploads($db,'attachments',$id,(int)$u['id']); if ($aerr) flash($aerr,'err');
                if ($type==='Testing Passed' || $type==='Go-Live / Completed') {
                    // Master data update from the SOF details (audit-logged per field).
                    $db->prepare('UPDATE customers SET service=COALESCE(NULLIF(?,?),service) WHERE id=?')->execute([$t['service'],$t['service'],(int)$t['customer_id']]);
                    audit($db,'Master record updated from SOF on '.$type,$t['reference'],['service']);
                    $db->prepare('UPDATE tickets SET next_update_due=NULL WHERE id=?')->execute([$id]);
                }
                if (in_array($type,['Deployment Testing Update','Testing Passed'],true)) $db->prepare('UPDATE tickets SET next_update_due=NULL WHERE id=?')->execute([$id]);
                audit($db,'Remark added ('.$type.')',$t['reference'],[]);
                flash('Update added.');
            }
        } elseif ($act==='forward' && ($u['role']??'')!=='Viewer') {
            $note=trim($_POST['note']??'');
            if ($note==='') flash('A note is required to forward a ticket.','err');
            else {
                $to=trim($_POST['forward_to']??'');
                $db->prepare('UPDATE tickets SET pending_at=?, department=? WHERE id=?')->execute([$to,$to,$id]);
                add_remark($db,$id,'Forwarded to '.$to.': '.$note,'General Update',$u);
                $aerr=handle_uploads($db,'attachments',$id,(int)$u['id']); if ($aerr) flash($aerr,'err');
                audit($db,'Ticket forwarded to '.$to,$t['reference'],['pending_at']);
                flash('Ticket forwarded to '.e($to).'.');
            }
        } elseif ($act==='call_log') {
            $outcome=trim($_POST['outcome']??''); $lvl=trim($_POST['level']??'Level 1');
            if ($outcome!=='') { add_remark($db,$id,'Escalation call ('.$lvl.'): '.$outcome,'Vendor Update',$u,true);
                $db->prepare('UPDATE tickets SET next_update_due=NULL WHERE id=?')->execute([$id]);
                audit($db,'Update call logged ('.$lvl.')',$t['reference'],[]); flash('Call logged.'); }
        } elseif ($act==='resolve' || $act==='close') {
            $isComplaint=$t['type']==='Complaint';
            $rfo=trim($_POST['rfo_reason']??''); $rca=trim($_POST['rca']??'');
            if ($isComplaint && ($rfo===''||$rca==='')) {
                flash('RFO (Reason for Outage) and RCA (root cause) are mandatory to resolve/close a Complaint.','err');
            } else {
                if ($act==='close') {
                    $pend=$db->prepare("SELECT step FROM approvals WHERE ticket_id=? AND status<>'Approved' ORDER BY step_order"); $pend->execute([$id]);
                    $missing=$pend->fetchAll(PDO::FETCH_COLUMN);
                    if ($missing) { flash('Approval chain incomplete - pending: '.implode(', ',$missing).'. This ticket cannot close yet.','err'); redirect('index.php?page=ticket&id='.$id); }
                }
                $fields=['rfo_reason'=>$rfo?:null,'rca'=>$rca?:null,'rfo_by'=>$u['name'],'rfo_department'=>$u['department']??null,'rfo_at'=>date('Y-m-d H:i:s')];
                if ($act==='resolve') { $fields+=['status'=>'Resolved','resolved_at'=>date('Y-m-d H:i:s'),'resolved_by'=>$u['name'],'resolved_by_department'=>$u['department']??null]; }
                else { $fields+=['status'=>'Closed','closed_at'=>date('Y-m-d H:i:s'),'closed_by'=>$u['name'],'closed_by_department'=>$u['department']??null];
                       if (!$t['resolved_at']) { $fields+=['resolved_at'=>date('Y-m-d H:i:s'),'resolved_by'=>$u['name'],'resolved_by_department'=>$u['department']??null]; } }
                $set=implode(',', array_map(fn($k)=>"$k=?", array_keys($fields)));
                $db->prepare("UPDATE tickets SET $set WHERE id=?")->execute([...array_values($fields),$id]);
                if ($rfo) $db->prepare('INSERT INTO kb_articles(title,category,rfo_reason,solution,created_by) VALUES(?,?,?,?,?)')
                    ->execute(['Solution: '.$t['reference'].' - '.$t['category'],$t['category'],$rfo,$rca,$u['name']]);
                audit($db,ucfirst($act).'d ticket',$t['reference'],array_keys($fields));
                $t2=get_ticket($db,$id); $t2['customer_email']=$t['customer_email'];
                notify_customer($db,$t2,'resolved');
                flash('Ticket '.$act.'d.');
            }
        } elseif ($act==='approve') {
            $step=(string)($_POST['step']??'');
            $rows=$db->prepare('SELECT * FROM approvals WHERE ticket_id=? ORDER BY step_order'); $rows->execute([$id]);
            $blocked=false;
            foreach ($rows->fetchAll() as $a) { if ($a['step']===$step) break; if ($a['status']!=='Approved') $blocked=true; }
            if ($blocked) flash('Approvals must follow the order: Sales, then Accounts, then Technical.','err');
            else { $db->prepare("UPDATE approvals SET status='Approved', decided_by=?, decided_at=NOW(), note=? WHERE ticket_id=? AND step=?")
                     ->execute([$u['name'],trim($_POST['note']??''),$id,$step]);
                   audit($db,'Approval: '.$step.' approved',$t['reference'],['approvals']); flash('Approved: '.$step); }
        } elseif ($act==='welcome') {
            $body="Welcome! Your service is now live.\nTicket: ".$t['reference']."\nService: ".($t['service']??'');
            $res=smtp_send($db,(string)$t['customer_email'],'Welcome - '.$t['reference'],$body);
            $aerr=handle_uploads($db,'attachments',$id,(int)$u['id']); // screenshots are OPTIONAL
            if ($res===true) { add_remark($db,$id,'Welcome email sent to customer (screenshots optional).','Customer Update',$u); flash('Welcome email sent.'); }
            else flash((string)$res,'err');
        } elseif ($act==='share_results') {
            $res=smtp_send($db,(string)$t['customer_email'],'Testing Results - '.$t['reference'],"Testing results for your service (ticket ".$t['reference'].") are complete. Status: ".$t['status']);
            if ($res===true) { add_remark($db,$id,'Testing results shared with customer.','Customer Update',$u); flash('Testing results shared.'); } else flash((string)$res,'err');
        }
        redirect('index.php?page=ticket&id='.$id);
    }
    $t=get_ticket($db,$id);
    layout_start($db,$t['reference'],$u);
    echo '<h1>'.e($t['reference']).'</h1>';
    echo '<div class="card"><p><b>'.e($t['customer_company']).'</b> &middot; '.e($t['type']).' &middot; '.e($t['category']).' &middot; Priority: '.e($t['priority']).' &middot; Status: <b>'.e($t['status']).'</b> &middot; Pending At: '.e($t['pending_at']).'</p>';
    echo '<p>'.nl2br(e($t['requirement'])).'</p>';
    $sla=sla_status($t,$db); echo '<p>SLA: <span class="badge '.($sla==='Within SLA'?'okb':'bad').'">'.e($sla).'</span>';
    if ($t['next_update_due'] && strtotime((string)$t['next_update_due'])<time() && !in_array($t['status'],['Resolved','Closed'],true)) echo ' <span class="badge warn">UPDATE DUE - Take update now</span>';
    if ($t['deployment_priority']==='Immediate') echo ' <span class="badge bad">IMMEDIATE DEPLOYMENT</span>';
    echo '</p>';
    echo '<h3>Ticket Times</h3><table><tr><th>Opened At</th><th>Opened By</th><th>Resolved At</th><th>Resolved By</th><th>Closed At</th><th>Closed By</th><th>Downtime</th></tr><tr>';
    echo '<td>'.e($t['opened_at']).'</td><td>'.e($t['opened_by']).' ('.e($t['opened_by_department']).')</td><td>'.e($t['resolved_at']).'</td><td>'.e($t['resolved_by']).' ('.e($t['resolved_by_department']).')</td><td>'.e($t['closed_at']).'</td><td>'.e($t['closed_by']).' ('.e($t['closed_by_department']).')</td><td>'.e(downtime_text($t)).'</td></tr></table>';
    if ($t['confirmation_status']) echo '<h3>Customer Confirmation Call</h3><p>Status: <b>'.e($t['confirmation_status']).'</b> &middot; Called by: '.e($t['confirmation_by']).' &middot; Department: '.e($t['confirmation_department']).' &middot; At: '.e($t['confirmation_at']).'<br>'.e($t['confirmation_note']).'</p>';
    if ($t['rfo_reason']) echo '<h3>RFO / RCA - Reason for Outage &amp; Root Cause Analysis</h3><p><b>RFO:</b> '.e($t['rfo_reason']).'<br><b>RCA:</b> '.nl2br(e($t['rca'])).'<br><span class="muted">By '.e($t['rfo_by']).' ('.e($t['rfo_department']).') at '.e($t['rfo_at']).'</span></p>';
    echo '<p class="muted">Customer portal link (customer-safe view): <code>index.php?page=portal&amp;token='.e($t['portal_token']).'</code></p>';
    echo '</div>';
    // Approvals
    $apps=$db->prepare('SELECT * FROM approvals WHERE ticket_id=? ORDER BY step_order'); $apps->execute([$id]); $apps=$apps->fetchAll();
    if ($apps) { echo '<div class="card"><h2>Approval Chain (Sales &rarr; Accounts &rarr; Technical)</h2>';
        foreach ($apps as $a) { echo '<p>'.e($a['step']).': <b>'.e($a['status']).'</b> '.e($a['decided_by']??'').'';
            if ($a['status']!=='Approved') echo ' <form method="post" style="display:inline">'.csrf_field().'<input type="hidden" name="act" value="approve"><input type="hidden" name="step" value="'.e($a['step']).'"><input name="note" placeholder="Note" style="width:180px"><button type="submit">Approve</button></form>';
            echo '</p>'; } echo '</div>'; }
    // Vendor escalation panel
    if ($t['vendor_id']) {
        $v=$db->prepare('SELECT * FROM vendors WHERE id=?'); $v->execute([(int)$t['vendor_id']]); $v=$v->fetch();
        if ($v) { echo '<div class="card"><h2>Call Escalation - '.e($v['name']).'</h2>';
            $ageH=$t['opened_at']?(time()-strtotime((string)$t['opened_at']))/3600:0; $lvl=1;
            if (!empty($v['esc3_after_hours']) && $ageH>=(float)$v['esc3_after_hours']) $lvl=3; elseif (!empty($v['esc2_after_hours']) && $ageH>=(float)$v['esc2_after_hours']) $lvl=2;
            echo '<p>Current escalation level by elapsed time: <b>Level '.$lvl.'</b></p><table><tr><th>Level</th><th>Name</th><th>Title</th><th>Phone</th><th>Email</th><th>After (hours)</th></tr>';
            for ($i=1;$i<=3;$i++) echo '<tr><td>'.$i.'</td><td>'.e($v['esc'.$i.'_name']).'</td><td>'.e($v['esc'.$i.'_title']).'</td><td><a href="tel:'.e($v['esc'.$i.'_phone']).'">'.e($v['esc'.$i.'_phone']).'</a></td><td>'.e($v['esc'.$i.'_email']).'</td><td>'.e((string)($v['esc'.$i.'_after_hours']??'')).'</td></tr>';
            echo '</table><form method="post">'.csrf_field().'<input type="hidden" name="act" value="call_log">';
            echo '<label>Level</label><select name="level"><option>Level 1</option><option>Level 2</option><option>Level 3</option></select>';
            echo '<label>Call Outcome (Update received / No Answer / New ETR)</label><input name="outcome" required>';
            echo '<button type="submit">Log Update Call (internal only)</button></form></div>'; }
    }
    // Timeline
    echo '<div class="card"><h2>Timeline (append-only)</h2>';
    $rm=$db->prepare('SELECT * FROM remarks WHERE ticket_id=? ORDER BY id'); $rm->execute([$id]);
    foreach ($rm as $r) echo '<p><span class="badge">'.e($r['update_type']).'</span> <b>'.e($r['author_name']).'</b> <span class="muted">('.e($r['author_department']).' &middot; '.e($r['created_at']).')</span><br>'.nl2br(e($r['body'])).'</p>';
    $at=$db->prepare('SELECT * FROM attachments WHERE ticket_id=? ORDER BY id'); $at->execute([$id]);
    foreach ($at as $a) echo '<p class="muted">Attachment: '.e($a['file_name']).' ('.number_format($a['size_bytes']/1024,1).' KB) &middot; '.e($a['created_at']).'</p>';
    echo '</div>';
    if (($u['role']??'')!=='Viewer') {
        echo '<div class="card"><h2>Add Update</h2><form method="post" enctype="multipart/form-data">'.csrf_field().'<input type="hidden" name="act" value="remark">';
        echo '<label>Update Type</label><select name="update_type">'; foreach (UPDATE_TYPES as $ut) echo '<option>'.e($ut).'</option>'; echo '</select>';
        echo '<label>Update</label><textarea name="body" rows="3" required></textarea>';
        echo '<label>Attachments (optional)</label><input type="file" name="attachments[]" multiple>';
        echo '<button type="submit">Add Update</button></form></div>';
        echo '<div class="card"><h2>Forward Ticket</h2><form method="post" enctype="multipart/form-data">'.csrf_field().'<input type="hidden" name="act" value="forward">';
        echo '<label>Forward To (department / vendor / person)</label><input name="forward_to" required>';
        echo '<label>Department</label><input name="to_department">';
        echo '<label>Note (required)</label><textarea name="note" rows="2" required></textarea>';
        echo '<label>Attachments (optional)</label><input type="file" name="attachments[]" multiple>';
        echo '<button type="submit">Forward Ticket</button></form></div>';
        echo '<div class="card"><h2>Resolve / Close</h2><form method="post">'.csrf_field();
        echo '<label>RFO - Reason for Outage (mandatory for Complaints)</label><select name="rfo_reason"><option value="">-- select --</option>';
        foreach (RFO_REASONS as $r) echo '<option'.($t['rfo_reason']===$r?' selected':'').'>'.e($r).'</option>'; echo '</select>';
        echo '<label>RCA - Root Cause Analysis (mandatory for Complaints)</label><textarea name="rca" rows="3">'.e($t['rca']).'</textarea>';
        echo '<button type="submit" name="act" value="resolve">Resolve</button> <button type="submit" name="act" value="close" class="btn secondary" formnovalidate>Close</button></form>';
        echo '<p><form method="post" enctype="multipart/form-data" style="display:inline">'.csrf_field().'<input type="hidden" name="act" value="share_results"><button type="submit">Share Testing Results</button></form> ';
        echo '<form method="post" enctype="multipart/form-data" style="display:inline">'.csrf_field().'<input type="hidden" name="act" value="welcome"><input type="file" name="attachments[]" multiple> <button type="submit">Send Welcome Email (screenshots optional)</button></form></p></div>';
    }
    layout_end();
}
function page_portal(PDO $db): void {
    $token=(string)($_GET['token']??'');
    $st=$db->prepare('SELECT reference,customer_company,type,category,status,etr,opened_at,resolved_at FROM tickets WHERE portal_token=?');
    $st->execute([$token]); $t=$st->fetch();
    print_start($db,'Ticket Status');
    if (!$t) { echo '<p>Invalid link.</p>'; }
    else { echo '<div class="card"><h1>Ticket Status</h1><p><b>'.e($t['reference']).'</b></p><p>'.e($t['customer_company']).'<br>'.e($t['type']).' - '.e($t['category']).'<br>Status: <b>'.e($t['status']).'</b>';
        if ($t['etr']) echo '<br>ETR: '.e($t['etr']);
        echo '<br>Opened: '.e($t['opened_at']); if ($t['resolved_at']) echo '<br>Resolved: '.e($t['resolved_at']); echo '</p></div>'; }
    print_end();
}
