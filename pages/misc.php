<?php
declare(strict_types=1);
/* Co-Location, Stock Room, Knowledge Base. */
function page_colo(PDO $db): void {
    $u=require_login();
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($u['role']??'')!=='Viewer') {
        csrf_check();
        if (($_POST['act']??'')==='site') {
            $db->prepare('INSERT INTO colo_sites(site,kind,customer,rack,u_space,power,equipment,status) VALUES(?,?,?,?,?,?,?,?)')
               ->execute([$_POST['site'],$_POST['kind'],$_POST['customer']??'',$_POST['rack']??'',$_POST['u_space']??'',$_POST['power']??'',$_POST['equipment']??'','Installed']);
            audit($db,'Co-location inventory added',(string)$_POST['site'],['site']);
        } else {
            $db->prepare('INSERT INTO colo_visits(site_id,person,entry_at,exit_at,purpose,equipment_in,equipment_out,serials,approver) VALUES(?,?,?,?,?,?,?,?,?)')
               ->execute([(int)$_POST['site_id'],$_POST['person'],$_POST['entry_at']?:null,$_POST['exit_at']?:null,$_POST['purpose']??'',$_POST['equipment_in']??'',$_POST['equipment_out']??'',$_POST['serials']??'',$_POST['approver']??'']);
            audit($db,'Co-location visit logged',(string)$_POST['person'],['visit']);
        }
        flash('Saved.'); redirect('index.php?page=colo');
    }
    layout_start($db,'Co-Location',$u);
    echo '<h1>Co-Location - Data Center Access &amp; Inventory</h1>';
    $sites=$db->query('SELECT * FROM colo_sites ORDER BY site')->fetchAll();
    echo '<div class="card"><table><tr><th>Site</th><th>Kind</th><th>Customer</th><th>Rack</th><th>U</th><th>Power</th><th>Status</th></tr>';
    foreach ($sites as $s) echo '<tr><td>'.e($s['site']).'</td><td>'.e($s['kind']).'</td><td>'.e($s['customer']).'</td><td>'.e($s['rack']).'</td><td>'.e($s['u_space']).'</td><td>'.e($s['power']).'</td><td>'.e($s['status']).'</td></tr>';
    echo '</table></div>';
    if (($u['role']??'')!=='Viewer') {
        echo '<div class="card"><h2>Add Inventory</h2><form method="post">'.csrf_field().'<input type="hidden" name="act" value="site">';
        echo '<label>Site</label><input name="site" required><label>Kind</label><select name="kind"><option>Primary</option><option>DR</option></select>';
        echo '<label>Customer</label><input name="customer"><label>Rack</label><input name="rack"><label>U Space</label><input name="u_space"><label>Power</label><input name="power"><label>Equipment</label><input name="equipment">';
        echo '<button type="submit">Add</button></form></div>';
        echo '<div class="card"><h2>Log a Visit / Movement</h2><form method="post">'.csrf_field().'<input type="hidden" name="act" value="visit">';
        echo '<label>Site</label><select name="site_id">'; foreach ($sites as $s) echo '<option value="'.(int)$s['id'].'">'.e($s['site']).'</option>'; echo '</select>';
        echo '<label>Person</label><input name="person" required><label>Entry</label><input type="datetime-local" name="entry_at"><label>Exit</label><input type="datetime-local" name="exit_at">';
        echo '<label>Purpose</label><input name="purpose"><label>Equipment In</label><input name="equipment_in"><label>Equipment Out</label><input name="equipment_out"><label>Serials</label><input name="serials"><label>Approver</label><input name="approver">';
        echo '<button type="submit">Log Visit</button></form></div>';
    }
    layout_end();
}
function page_stock(PDO $db): void {
    $u=require_login();
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($u['role']??'')!=='Viewer') {
        csrf_check(); $act=$_POST['act']??'';
        if ($act==='add') { $db->prepare('INSERT INTO stock_items(name,serial,total) VALUES(?,?,?)')->execute([$_POST['name'],$_POST['serial']??'',(int)$_POST['total']]); audit($db,'Stock item added',(string)$_POST['name'],['stock']); }
        if ($act==='issue') { $it=$db->prepare('SELECT * FROM stock_items WHERE id=?'); $it->execute([(int)$_POST['id']]); $it=$it->fetch();
            $issued=(int)$db->query('SELECT COUNT(*) FROM stock_items WHERE issued_to IS NOT NULL')->fetchColumn();
            // Simple per-item issue record stored on the item row (holder = customer).
            $db->prepare('UPDATE stock_items SET issued_to=?, issued_at=NOW(), returned_at=NULL WHERE id=?')->execute([$_POST['issued_to'],(int)$_POST['id']]);
            audit($db,'Stock issued to '.(string)$_POST['issued_to'],(string)($it['name']??''),['issued_to']); }
        if ($act==='return') { $db->prepare('UPDATE stock_items SET returned_at=NOW() WHERE id=?')->execute([(int)$_POST['id']]); audit($db,'Stock returned','stock',['returned_at']); }
        flash('Saved.'); redirect('index.php?page=stock');
    }
    layout_start($db,'Stock Room',$u);
    echo '<h1>Stock / Equipment Room</h1><div class="card"><table><tr><th>Item</th><th>Serial</th><th>Total</th><th>Issued To</th><th>Issued At</th><th>Returned At</th><th></th></tr>';
    foreach ($db->query('SELECT * FROM stock_items ORDER BY name') as $s) {
        echo '<tr><td>'.e($s['name']).'</td><td>'.e($s['serial']).'</td><td>'.(int)$s['total'].'</td><td>'.e($s['issued_to']).'</td><td>'.e($s['issued_at']).'</td><td>'.e($s['returned_at']).'</td>';
        echo '<td><form method="post" style="display:inline">'.csrf_field().'<input type="hidden" name="act" value="issue"><input type="hidden" name="id" value="'.(int)$s['id'].'"><input name="issued_to" placeholder="Customer / holder" style="width:150px"><button>Issue</button></form> ';
        echo '<form method="post" style="display:inline">'.csrf_field().'<input type="hidden" name="act" value="return"><input type="hidden" name="id" value="'.(int)$s['id'].'"><button>Return</button></form></td></tr>'; }
    echo '</table></div>';
    if (($u['role']??'')!=='Viewer') { echo '<div class="card"><h2>Add Item</h2><form method="post">'.csrf_field().'<input type="hidden" name="act" value="add"><label>Name</label><input name="name" required><label>Serial</label><input name="serial"><label>Total Quantity</label><input type="number" name="total" value="1"><button type="submit">Add Item</button></form></div>'; }
    layout_end();
}
function page_kb(PDO $db): void {
    $u=require_login();
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($u['role']??'')!=='Viewer') {
        csrf_check();
        $db->prepare('INSERT INTO kb_articles(title,category,rfo_reason,solution,created_by) VALUES(?,?,?,?,?)')
           ->execute([$_POST['title'],$_POST['category']??'',$_POST['rfo_reason']??'',$_POST['solution'],$u['name']]);
        audit($db,'Knowledge Base article saved',(string)$_POST['title'],['kb']); flash('Saved.'); redirect('index.php?page=kb');
    }
    layout_start($db,'Knowledge Base',$u);
    echo '<h1>Knowledge Base</h1><p class="muted">Resolved RFO/RCA solutions are added automatically when tickets resolve; add manual articles here. Suggestions from this base appear while lodging a ticket.</p>';
    foreach ($db->query('SELECT * FROM kb_articles ORDER BY id DESC LIMIT 100') as $a) {
        echo '<div class="card"><b>'.e($a['title']).'</b> <span class="badge">'.e($a['rfo_reason']).'</span><p>'.nl2br(e($a['solution'])).'</p><p class="muted">'.e($a['created_by']).' &middot; '.e($a['created_at']).'</p></div>'; }
    if (($u['role']??'')!=='Viewer') { echo '<div class="card"><h2>Add Article</h2><form method="post">'.csrf_field();
        echo '<label>Title</label><input name="title" required><label>Category</label><input name="category"><label>RFO Reason</label><select name="rfo_reason"><option value=""></option>';
        foreach (RFO_REASONS as $r) echo '<option>'.e($r).'</option>';
        echo '</select><label>Solution</label><textarea name="solution" rows="4" required></textarea><button type="submit">Save Article</button></form></div>'; }
    layout_end();
}
