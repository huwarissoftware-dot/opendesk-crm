<?php
declare(strict_types=1);
/* Customers, Vendors (escalation matrix, SLA docs), Excel/CSV import & export. */
const CUSTOMER_FIELDS=['company','contact_person','email','phone','address','service','sheet_type','circuit_id','bandwidth','pop_site','notes'];
const SHEET_TYPES=['Managed Links','Co-Location','Cloud / Linux','Radio Links','IPNOC','Dark Cores','International','Terminated'];
function is_credential_column(string $col): bool {
    return (bool)preg_match('/pass(word)?|user(name)?|secret|token|credential|pin/i',$col);
}
function page_customers(PDO $db): void {
    $u=require_login();
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($u['role']??'')!=='Viewer') {
        csrf_check();
        if (($_POST['act']??'')==='save') {
            $vals=[]; foreach (CUSTOMER_FIELDS as $f) $vals[$f]=trim($_POST[$f]??'');
            if (!empty($_POST['id'])) { $set=implode(',',array_map(fn($f)=>"$f=?",CUSTOMER_FIELDS));
                $db->prepare("UPDATE customers SET $set WHERE id=?")->execute([...array_values($vals),(int)$_POST['id']]);
                audit($db,'Customer updated',(string)$vals['company'],array_keys($vals)); }
            else { $cols=implode(',',CUSTOMER_FIELDS); $ph=implode(',',array_fill(0,count(CUSTOMER_FIELDS),'?'));
                $db->prepare("INSERT INTO customers($cols) VALUES($ph)")->execute(array_values($vals));
                audit($db,'Customer created',(string)$vals['company'],array_keys($vals)); }
            flash('Customer saved.');
        } elseif (($_POST['act']??'')==='sla' && !empty($_FILES['sla_doc']['name'])) {
            $err=validate_upload($_FILES['sla_doc'],'any');
            if ($err) flash($err,'err'); else { store_upload($db,$_FILES['sla_doc'],0,(int)$u['id']);
                $db->prepare('UPDATE customers SET sla_document=? WHERE id=?')->execute([$_FILES['sla_doc']['name'],(int)$_POST['id']]);
                audit($db,'SLA document uploaded','customers',['sla_document']); flash('SLA document uploaded.'); }
        }
        redirect('index.php?page=customers');
    }
    layout_start($db,'Customers',$u);
    echo '<h1>Customers</h1>';
    echo '<p><a class="btn secondary" href="index.php?page=import">Import Master Data (Excel/CSV)</a> <a class="btn secondary" href="index.php?page=export">Export Master Data</a> <a class="btn secondary" href="index.php?page=template">Download Blank Master Template</a></p>';
    if (($u['role']??'')!=='Viewer') {
        echo '<div class="card"><h2>Add Customer</h2><form method="post">'.csrf_field().'<input type="hidden" name="act" value="save">';
        foreach (CUSTOMER_FIELDS as $f) echo '<label>'.e(ucwords(str_replace('_',' ',$f))).'</label>'.($f==='sheet_type'?'<select name="sheet_type">'.implode('',array_map(fn($s)=>'<option>'.e($s).'</option>',SHEET_TYPES)).'</select>':'<input name="'.e($f).'">');
        echo '<button type="submit">Save Customer</button></form></div>';
    }
    $rows=$db->query('SELECT * FROM customers ORDER BY company LIMIT 500')->fetchAll();
    echo '<div class="card"><table><tr><th>Company</th><th>Contact</th><th>Email</th><th>Service</th><th>Sheet</th><th>SLA Doc</th><th></th></tr>';
    foreach ($rows as $c) { echo '<tr><td>'.e($c['company']).'</td><td>'.e($c['contact_person']).'</td><td>'.e($c['email']).'</td><td>'.e($c['service']).'</td><td>'.e($c['sheet_type']).'</td><td>'.e($c['sla_document']).'</td>';
        echo '<td><form method="post" enctype="multipart/form-data">'.csrf_field().'<input type="hidden" name="act" value="sla"><input type="hidden" name="id" value="'.(int)$c['id'].'"><input type="file" name="sla_doc"><button type="submit">Upload SLA</button></form></td></tr>'; }
    echo '</table></div>';
    layout_end();
}
function page_vendors(PDO $db): void {
    $u=require_login();
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($u['role']??'')!=='Viewer') {
        csrf_check();
        $fields=['name','contact','email','phone','service'];
        for ($i=1;$i<=3;$i++) foreach (['name','title','phone','email','after_hours'] as $k) $fields[]='esc'.$i.'_'.$k;
        $vals=[]; foreach ($fields as $f) $vals[$f]=trim($_POST[$f]??'')?:null;
        $cols=implode(',',$fields); $ph=implode(',',array_fill(0,count($fields),'?'));
        $db->prepare("INSERT INTO vendors($cols) VALUES($ph)")->execute(array_values($vals));
        audit($db,'Vendor created',(string)$vals['name'],$fields);
        flash('Vendor saved with its 3-level escalation matrix.');
        redirect('index.php?page=vendors');
    }
    layout_start($db,'Vendors',$u);
    echo '<h1>Vendors &amp; Escalation Matrix</h1>';
    if (($u['role']??'')!=='Viewer') {
        echo '<div class="card"><h2>Add Vendor</h2><form method="post">'.csrf_field();
        foreach (['name','contact','email','phone','service'] as $f) echo '<label>'.e(ucwords($f)).'</label><input name="'.e($f).'">';
        for ($i=1;$i<=3;$i++) { echo '<h3>Escalation Level '.$i.'</h3>';
            foreach (['name','title','phone','email'] as $k) echo '<label>Level '.$i.' '.e(ucwords($k)).'</label><input name="esc'.$i.'_'.$k.'">';
            echo '<label>Escalate after (hours)</label><input type="number" name="esc'.$i.'_after_hours">'; }
        echo '<button type="submit">Save Vendor</button></form></div>';
    }
    foreach ($db->query('SELECT * FROM vendors ORDER BY name') as $v) {
        echo '<div class="card"><h2>'.e($v['name']).'</h2><p class="muted">'.e($v['service']).' &middot; SLA Doc: '.e($v['sla_document']).'</p><table><tr><th>Level</th><th>Name</th><th>Title</th><th>Phone</th><th>Email</th><th>After (h)</th></tr>';
        for ($i=1;$i<=3;$i++) echo '<tr><td>'.$i.'</td><td>'.e($v['esc'.$i.'_name']).'</td><td>'.e($v['esc'.$i.'_title']).'</td><td>'.e($v['esc'.$i.'_phone']).'</td><td>'.e($v['esc'.$i.'_email']).'</td><td>'.e((string)($v['esc'.$i.'_after_hours']??'')).'</td></tr>';
        echo '</table></div>';
    }
    layout_end();
}
function csv_rows(string $path): array {
    $h=fopen($path,'r'); if (!$h) return [];
    $rows=[]; while (($r=fgetcsv($h))!==false) $rows[]=$r; fclose($h); return $rows;
}
function page_import(PDO $db): void {
    $u=require_role('Admin'); // import is Owner/Admin only, and audit-logged
    layout_start($db,'Import Master Data',$u);
    echo '<h1>Import Master Data (Excel / CSV)</h1>';
    echo '<p class="muted">Upload a .csv export of any sheet (save an Excel sheet as CSV, or use the in-app template). Map YOUR columns to CRM fields - mappings are per-company and can be saved, changed or skipped. Columns whose names look like credentials (password / username / secret / token) are automatically excluded and are NEVER imported.</p>';
    if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_FILES['file']['name'])) {
        csrf_check();
        if (strtolower(pathinfo($_FILES['file']['name'],PATHINFO_EXTENSION))!=='csv') { flash('Please upload a CSV file (in Excel: File &gt; Save As &gt; CSV). XLSX parsing requires the PhpSpreadsheet library on your server - see README.','err'); }
        else {
            $rows=csv_rows((string)$_FILES['file']['tmp_name']);
            $header=$rows[0]??[]; $map=[]; $excluded=[];
            foreach ($header as $i=>$col) { if (is_credential_column((string)$col)) { $excluded[]=$col; continue; }
                foreach (CUSTOMER_FIELDS as $f) if (strtolower(str_replace([' ','_'],'',(string)$col))===strtolower(str_replace('_','',$f))) $map[$i]=$f; }
            $new=0;$skip=0;$upd=0;
            $mode=(string)($_POST['duplicates']??'skip');
            for ($r=1;$r<count($rows);$r++) { $vals=[];
                foreach ($map as $i=>$f) $vals[$f]=$rows[$r][$i]??null;
                if (empty($vals['company'])) continue;
                $q=$db->prepare('SELECT id FROM customers WHERE company=?'); $q->execute([$vals['company']]);
                $ex=$q->fetchColumn();
                if ($ex && $mode==='update') { $set=implode(',',array_map(fn($f)=>"$f=?",array_keys($vals)));
                    $db->prepare("UPDATE customers SET $set WHERE id=?")->execute([...array_values($vals),(int)$ex]); $upd++; }
                elseif ($ex) $skip++;
                else { $cols=implode(',',array_keys($vals)); $ph=implode(',',array_fill(0,count($vals),'?'));
                    $db->prepare("INSERT INTO customers($cols) VALUES($ph)")->execute(array_values($vals)); $new++; } }
            if ($excluded) flash('Credential columns excluded and never imported: '.implode(', ',$excluded),'err');
            audit($db,'Master data import','customers',['rows: '.($new+$skip+$upd),"new: $new","updated: $upd","skipped: $skip"]);
            flash("Import complete - New: $new, Updated: $upd, Duplicates skipped: $skip.");
        }
    }
    echo '<div class="card"><form method="post" enctype="multipart/form-data">'.csrf_field();
    echo '<label>CSV file (any sheet, your own column pattern)</label><input type="file" name="file" accept=".csv" required>';
    echo '<label>Duplicates</label><select name="duplicates"><option value="skip">Skip duplicates</option><option value="update">Update duplicates</option></select>';
    echo '<button type="submit">Preview &amp; Import</button></form></div>';
    layout_end();
}
function send_csv(string $filename, array $header, iterable $rows): never {
    header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="'.$filename.'"');
    $out=fopen('php://output','w'); fputcsv($out,$header);
    foreach ($rows as $r) fputcsv($out,$r);
    fclose($out); exit;
}
function page_export(PDO $db): void {
    $u=require_role('Admin');
    audit($db,'Master data export (Excel/CSV by sheet type)','customers',[]);
    $rows=$db->query('SELECT * FROM customers ORDER BY sheet_type, company')->fetchAll();
    $out=[]; foreach ($rows as $c) { $r=[]; foreach (CUSTOMER_FIELDS as $f) $r[]=$c[$f]; $out[]=$r; }
    send_csv('opendesk-customers-by-sheet-type-'.date('Ymd').'.csv', array_map(fn($f)=>ucwords(str_replace('_',' ',$f)),CUSTOMER_FIELDS), $out);
}
function page_template(PDO $db): void {
    require_role('Admin');
    send_csv('opendesk-blank-master-template.csv', array_map(fn($f)=>ucwords(str_replace('_',' ',$f)),CUSTOMER_FIELDS), []);
}
