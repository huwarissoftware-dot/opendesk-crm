<?php
declare(strict_types=1);
/* Tickets: list, lodge (incl. SOF flow), detail with remarks/forward/resolve/close. */
function alert_user(PDO $db, ?int $userId, string $msg, ?int $ticketId): void {
    if (!$userId) return;
    $db->prepare('INSERT INTO alerts(user_id,message,ticket_id) VALUES(?,?,?)')->execute([$userId,$msg,$ticketId]);
}
function page_tickets(PDO $db): void {
    $u=require_login();
    layout_start($db,'Tickets',$u);
    echo '<h1>Tickets</h1><p><a class="btn" href="index.php?page=ticket_new">Lodge Ticket</a> ';
    echo '<a class="btn secondary" href="index.php?page=ticket_new&sof=1">Generate SOF Ticket</a></p>';
    $where=[]; $args=[];
    if (!empty($_GET['status'])) { $where[]='status=?'; $args[]=$_GET['status']; }
    if (!empty($_GET['q'])) { $where[]='(reference LIKE ? OR customer_company LIKE ?)'; $args[]='%'.$_GET['q'].'%'; $args[]='%'.$_GET['q'].'%'; }
    $sql='SELECT * FROM tickets'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id DESC LIMIT 300';
    $st=$db->prepare($sql); $st->execute($args);
    echo '<div class="card"><table><tr><th>Reference</th><th>Customer</th><th>Type</th><th>Category</th><th>Priority</th><th>Status</th><th>Pending At</th><th>SLA</th></tr>';
    foreach ($st as $t) {
        $sla=sla_status($t,$db);
        echo '<tr><td><a href="index.php?page=ticket&id='.(int)$t['id'].'">'.e($t['reference']).'</a></td><td>'.e($t['customer_company']).'</td><td>'.e($t['type']).'</td><td>'.e($t['category']).'</td><td>'.e($t['priority']).'</td><td>'.e($t['status']).'</td><td>'.e($t['pending_at']).'</td><td><span class="badge '.($sla==='Within SLA'?'okb':'bad').'">'.e($sla).'</span></td></tr>';
    }
    echo '</table></div>';
    layout_end();
}
function customer_options(PDO $db): string {
    $out='';
    foreach ($db->query('SELECT id,company FROM customers ORDER BY company') as $c) $out.='<option value="'.(int)$c['id'].'">'.e($c['company']).'</option>';
    return $out;
}
function page_ticket_new(PDO $db): void {
    $u=require_role('Team Member');
    $sof=!empty($_GET['sof']);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        csrf_check();
        $isSof=!empty($_POST['sof']);
        $category=(string)($_POST['category']??'Link Down');
        $type=ticket_type_for_category($category);
        $customerId=(int)($_POST['customer_id']??0);
        $customer=null;
        if ($isSof && !empty($_POST['new_company'])) {
            // SOF flow: a NEW client's customer record is auto-created from the SOF details.
            $st=$db->prepare('INSERT INTO customers(company,contact_person,email,emails,phone,address,service,sheet_type) VALUES(?,?,?,?,?,?,?,?)');
            $st->execute([trim($_POST['new_company']),trim($_POST['contact_person']??''),trim($_POST['new_email']??''),trim($_POST['new_email']??''),trim($_POST['phone']??''),trim($_POST['address']??''),trim($_POST['service']??''),'Managed Links']);
            $customerId=(int)$db->lastInsertId();
            audit($db,'Customer auto-created from SOF','customers',['company','contact_person','email','service']);
        }
        if ($customerId) { $q=$db->prepare('SELECT * FROM customers WHERE id=?'); $q->execute([$customerId]); $customer=$q->fetch(); }
        if (!$customer) { flash('Please select a customer (or enter the new client details for an SOF).','err'); }
        else {
            if ($isSof && (($_FILES['sof_pdf']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)) {
                flash('The SOF form PDF is required in the SOF flow.','err'); redirect('index.php?page=ticket_new&sof=1');
            }
            $db->beginTransaction();
            try {
                $ref=next_ticket_reference($db,$customer);
                $confStatus=$_POST['confirmation_status']??null;
                $st=$db->prepare('INSERT INTO tickets(reference,customer_id,customer_company,customer_email,type,category,priority,requirement,service,address,status,pending_at,department,assignee_id,vendor_id,etr,deployment_priority,sof_order_type,sof_effective_date,confirmation_status,confirmation_by,confirmation_department,confirmation_at,confirmation_note,portal_token,opened_at,opened_by,opened_by_department) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)');
                $st->execute([$ref,$customerId,$customer['company'],$customer['email'],$type,$category,$_POST['priority']??'Medium',trim($_POST['requirement']??''),trim($_POST['service']??'')?:$customer['service'],trim($_POST['address']??'')?:$customer['address'],'Open',trim($_POST['department']??'Online Technical Support'),trim($_POST['department']??''),!empty($_POST['assignee_id'])?(int)$_POST['assignee_id']:null,!empty($_POST['vendor_id'])?(int)$_POST['vendor_id']:null,!empty($_POST['etr'])?$_POST['etr']:null,$isSof?($_POST['deployment_priority']??'Normal'):null,$isSof?($_POST['sof_order_type']??'SOF'):null,$isSof&&!empty($_POST['sof_effective_date'])?$_POST['sof_effective_date']:null,
                    // Confirmation Call: caller + department come ONLY from the creating account.
                    $confStatus?:null, $confStatus?$u['name']:null, $confStatus?$u['department']:null, $confStatus?date('Y-m-d H:i:s'):null, $confStatus?trim($_POST['confirmation_note']??''):null,
                    bin2hex(random_bytes(16)), $u['name'], $u['department']]);
                $tid=(int)$db->lastInsertId();
                if (in_array($category,['New Deployment (SOF)','Termination (TOS)','Cross Connect'],true)) {
                    foreach ([['Sales',1],['Accounts',2],['Technical',3]] as [$step,$ord]) {
                        $db->prepare('INSERT INTO approvals(ticket_id,step,step_order) VALUES(?,?,?)')->execute([$tid,$step,$ord]);
                    }
                }
                $db->commit();
            } catch (Throwable $ex) { $db->rollBack(); flash('Could not lodge ticket: '.$ex->getMessage(),'err'); redirect('index.php?page=ticket_new'); }
            if ($isSof && !empty($_FILES['sof_pdf']['name'])) {
                $err=validate_upload($_FILES['sof_pdf'],'any');
                if (!$err) { store_upload($db,$_FILES['sof_pdf'],$tid,(int)$u['id']); $db->prepare('UPDATE tickets SET sof_document=? WHERE id=?')->execute([$_FILES['sof_pdf']['name'],$tid]); }
            }
            $aerr=handle_uploads($db,'attachments',$tid,(int)$u['id']); if ($aerr) flash($aerr,'err');
            // Normal deployment: automatic update request to the assignee after 8-10 days (default 9).
            if ($isSof && ($_POST['deployment_priority']??'Normal')==='Normal') {
                $days=max(8,min(10,(int)(setting($db,'normal_deployment_update_days','9')?:9)));
                $db->prepare('UPDATE tickets SET next_update_due=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?')->execute([$days,$tid]);
            }
            audit($db,'Ticket lodged',$ref,['category','priority','department']);
            if (!empty($_POST['assignee_id'])) alert_user($db,(int)$_POST['assignee_id'],'New ticket assigned: '.$ref,$tid);
            $cust=$customer; $cust['email']=$customer['email'];
            notify_customer($db,['reference'=>$ref,'status'=>'Open','etr'=>$_POST['etr']??null,'customer_email'=>$customer['email']],'created');
            flash('Ticket lodged: '.$ref);
            redirect('index.php?page=ticket&id='.$tid);
        }
    }
    layout_start($db,$sof?'Generate SOF Ticket':'Lodge Ticket',$u);
    echo '<div class="card"><h1>'.($sof?'Generate SOF Ticket (Online Technical Support)':'Lodge Ticket').'</h1>';
    echo '<form method="post" enctype="multipart/form-data">'.csrf_field().($sof?'<input type="hidden" name="sof" value="1">':'');
    echo '<label>Existing Customer</label><select name="customer_id"><option value="">-- select --</option>'.customer_options($db).'</select>';
    if ($sof) {
        echo '<h3>New client (from this SOF) - record is auto-created</h3>';
        echo '<label>Company Name</label><input name="new_company">';
        echo '<label>Contact Person</label><input name="contact_person">';
        echo '<label>Email</label><input type="email" name="new_email">';
        echo '<label>SOF Form (PDF) - required in this flow</label><input type="file" name="sof_pdf" accept=".pdf">';
        echo '<label>Order Type</label><select name="sof_order_type"><option>SOF</option><option>TOS</option></select>';
        echo '<label>Effective Date</label><input type="date" name="sof_effective_date">';
        echo '<label>Deployment Priority</label><select name="deployment_priority"><option>Normal</option><option>Immediate</option></select>';
        echo '<p class="muted">Normal: the system automatically asks the assignee for an update after '.e(setting($db,'normal_deployment_update_days','9')?:'9').' days (configurable 8-10). Immediate: urgent handling.</p>';
    }
    echo '<label>Category</label><select name="category">';
    foreach (array_merge(COMPLAINT_CATEGORIES,REQUEST_CATEGORIES) as $c) echo '<option'.($sof&&$c==='New Deployment (SOF)'?' selected':'').'>'.e($c).'</option>';
    echo '</select>';
    echo '<label>Priority</label><select name="priority"><option>Critical</option><option>High</option><option selected>Medium</option><option>Low</option></select>';
    echo '<label>Requirement / Issue Detail</label><textarea name="requirement" rows="4" required></textarea>';
    echo '<label>Service</label><input name="service">';
    echo '<label>Address</label><input name="address">';
    echo '<label>Phone</label><input name="phone">';
    echo '<label>Department</label><select name="department">';
    foreach ($db->query('SELECT name FROM departments WHERE active=1') as $d) echo '<option>'.e($d['name']).'</option>';
    echo '</select>';
    echo '<label>Assignee (user ID, optional)</label><input name="assignee_id" type="number">';
    echo '<label>Vendor (optional)</label><select name="vendor_id"><option value="">-- none --</option>';
    foreach ($db->query('SELECT id,name FROM vendors ORDER BY name') as $v) echo '<option value="'.(int)$v['id'].'">'.e($v['name']).'</option>';
    echo '</select>';
    echo '<label>ETR (optional)</label><input type="datetime-local" name="etr">';
    echo '<h3>Customer Confirmation Call</h3><p class="muted">Called by: <b>'.e($u['name']).'</b> &middot; Department: <b>'.e($u['department']??'').'</b> (locked to your own account - another department cannot be entered).</p>';
    echo '<label>Call Status</label><select name="confirmation_status"><option value="">-- not called --</option><option>Confirmed</option><option>No Answer</option><option>Busy</option><option>Call Back</option></select>';
    echo '<label>Call Note</label><input name="confirmation_note">';
    echo '<label>Attachments (optional - images &amp; ZIP max 10 MB, other files max 15 MB)</label><input type="file" name="attachments[]" multiple>';
    echo '<button type="submit">Lodge Ticket</button></form></div>';
    // Knowledge Base suggestions while lodging
    $kb=$db->query('SELECT * FROM kb_articles ORDER BY id DESC LIMIT 5')->fetchAll();
    if ($kb) { echo '<div class="card"><h2>Knowledge Base suggestions</h2>'; foreach ($kb as $a) echo '<p><b>'.e($a['title']).'</b> - '.e(mb_strimwidth((string)$a['solution'],0,140,'...')).'</p>'; echo '</div>'; }
    layout_end();
}
