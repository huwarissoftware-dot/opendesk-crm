<?php
declare(strict_types=1);
function page_dashboard(PDO $db): void {
    $u=require_login();
    layout_start($db,'Dashboard',$u);
    $open=(int)$db->query("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('Resolved','Closed')")->fetchColumn();
    $resolved=(int)$db->query("SELECT COUNT(*) FROM tickets WHERE status='Resolved'")->fetchColumn();
    $complaints=(int)$db->query("SELECT COUNT(*) FROM tickets WHERE type='Complaint'")->fetchColumn();
    $requests=(int)$db->query("SELECT COUNT(*) FROM tickets WHERE type='Request'")->fetchColumn();
    $due=(int)$db->query("SELECT COUNT(*) FROM tickets WHERE next_update_due IS NOT NULL AND next_update_due<NOW() AND status NOT IN ('Resolved','Closed')")->fetchColumn();
    echo '<h1>Dashboard</h1><div class="grid">';
    foreach ([['Open / Pending',$open],['Resolved',$resolved],['Complaints',$complaints],['Requests',$requests],['Update Due',$due]] as [$l,$n]) {
        echo '<div class="card"><div class="muted">'.e($l).'</div><div class="stat">'.$n.'</div></div>';
    }
    echo '</div>';
    echo '<div class="card"><h2>Recent Activity</h2>';
    $rows=$db->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 10')->fetchAll();
    echo '<table><tr><th>When</th><th>Actor</th><th>Department</th><th>Action</th><th>Target</th></tr>';
    foreach ($rows as $r) echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['actor_name']).'</td><td>'.e($r['actor_department']).'</td><td>'.e($r['action']).'</td><td>'.e($r['target']).'</td></tr>';
    echo '</table></div>';
    echo '<div class="card"><h2>Latest Tickets</h2>';
    $tix=$db->query('SELECT * FROM tickets ORDER BY id DESC LIMIT 10')->fetchAll();
    echo '<table><tr><th>Reference</th><th>Customer</th><th>Type</th><th>Status</th><th>Pending At</th></tr>';
    foreach ($tix as $t) echo '<tr><td><a href="index.php?page=ticket&id='.(int)$t['id'].'">'.e($t['reference']).'</a></td><td>'.e($t['customer_company']).'</td><td>'.e($t['type']).'</td><td>'.e($t['status']).'</td><td>'.e($t['pending_at']).'</td></tr>';
    echo '</table></div>';
    layout_end();
}
function page_activity(PDO $db): void {
    $u=require_login();
    layout_start($db,'Activity / Audit Log',$u);
    echo '<h1>Activity / Audit Log</h1><p class="muted">Append-only. Remarks and audit entries are never edited or deleted in this application.</p>';
    $rows=$db->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 200')->fetchAll();
    echo '<div class="card"><table><tr><th>When</th><th>Actor</th><th>Email</th><th>Department</th><th>Action</th><th>Target</th><th>Changed Fields</th></tr>';
    foreach ($rows as $r) echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['actor_name']).'</td><td>'.e($r['actor_email']).'</td><td>'.e($r['actor_department']).'</td><td>'.e($r['action']).'</td><td>'.e($r['target']).'</td><td>'.e($r['changed_fields']).'</td></tr>';
    echo '</table></div>';
    $db->prepare('UPDATE alerts SET is_read=1 WHERE user_id=?')->execute([(int)$u['id']]);
    layout_end();
}
function page_tv(PDO $db): void {
    $u=require_login();
    layout_start($db,'TV Board',$u);
    echo '<h1>Live Board</h1>';
    $rows=$db->query("SELECT * FROM tickets WHERE status NOT IN ('Resolved','Closed') ORDER BY (deployment_priority='Immediate') DESC, id DESC LIMIT 30")->fetchAll();
    echo '<div class="grid">';
    foreach ($rows as $t) {
        echo '<div class="card"><b>'.e($t['reference']).'</b><br>'.e($t['customer_company']).'<br><span class="badge">'.e($t['status']).'</span> ';
        if ($t['deployment_priority']==='Immediate') echo '<span class="badge bad">IMMEDIATE</span>';
        if ($t['next_update_due'] && strtotime((string)$t['next_update_due'])<time()) echo ' <span class="badge warn">UPDATE DUE</span>';
        echo '<br><span class="muted">Pending at: '.e($t['pending_at']).'</span></div>';
    }
    echo '</div><script>setTimeout(function(){location.reload()},60000);</script>';
    layout_end();
}
