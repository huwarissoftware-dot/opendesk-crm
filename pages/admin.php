<?php
declare(strict_types=1);
/* Users management + Organization Structure + Settings (network, email, branding). */
function dept_options(PDO $db, ?string $sel=null): string {
    $out=''; foreach ($db->query('SELECT name FROM departments WHERE active=1 ORDER BY name') as $d) $out.='<option'.($sel===$d['name']?' selected':'').'>'.e($d['name']).'</option>'; return $out;
}
function desig_options(PDO $db, ?string $sel=null): string {
    $out=''; foreach ($db->query('SELECT name FROM designations WHERE active=1 ORDER BY name') as $d) $out.='<option'.($sel===$d['name']?' selected':'').'>'.e($d['name']).'</option>'; return $out;
}
function page_users(PDO $db): void {
    $actor=require_role('Admin');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        csrf_check(); $act=$_POST['act']??'';
        $targetId=(int)($_POST['id']??0);
        $target=null;
        if ($targetId) { $q=$db->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$targetId]); $target=$q->fetch(); }
        if ($act==='create') {
            $role=(string)($_POST['role']??'Team Member');
            if ($role==='Owner') { flash('Only one Owner exists - new accounts can be Admin at most.','err'); redirect('index.php?page=users'); }
            if (!is_owner($actor) && $role==='Admin' && false) { /* Admins may create up to Admin per company policy setting */ }
            $temp=bin2hex(random_bytes(6));
            $db->prepare('INSERT INTO users(name,email,password_hash,role,designation,department,phone,force_change) VALUES(?,?,?,?,?,?,?,1)')
               ->execute([trim($_POST['name']),trim($_POST['email']),hash_password($temp),$role,$_POST['designation']??'',$_POST['department']??'',$_POST['phone']??'']);
            audit($db,'User created: '.trim($_POST['email']),'users',['role: '.$role]);
            flash('User created. Temporary password (share it privately, it forces a change at first login): '.$temp);
        } elseif ($target && $act!=='create') {
            guard_owner_target($actor,$target); // Admin can never touch the Owner account.
            if ($act==='reset') { $temp=bin2hex(random_bytes(6));
                $db->prepare('UPDATE users SET password_hash=?, force_change=1 WHERE id=?')->execute([hash_password($temp),$targetId]);
                audit($db,'Password reset for '.$target['email'],'users',['password']); flash('Temporary password (forces change at first login): '.$temp); }
            if ($act==='toggle') { $db->prepare('UPDATE users SET active=1-active WHERE id=?')->execute([$targetId]); audit($db,'User active toggled: '.$target['email'],'users',['active']); flash('Updated.'); }
            if ($act==='update') { $role=(string)($_POST['role']??$target['role']); if ($role==='Owner' && !is_owner($actor)) $role=$target['role'];
                $db->prepare('UPDATE users SET name=?,role=?,designation=?,department=?,phone=? WHERE id=?')
                   ->execute([$_POST['name'],$role,$_POST['designation']??'',$_POST['department']??'',$_POST['phone']??'',$targetId]);
                audit($db,'User updated: '.$target['email'],'users',['name','role','designation','department']); flash('User updated.'); }
        }
        redirect('index.php?page=users');
    }
    layout_start($db,'Users',$actor);
    echo '<h1>Users</h1><p class="muted">Hierarchy: Owner &gt; Admin &gt; Team Lead &gt; Team Member &gt; Viewer. The Owner account can only be managed by the Owner. Every person sets their own password - a temporary password forces a change at first login.</p>';
    echo '<div class="card"><h2>Create User (Admin at most)</h2><form method="post">'.csrf_field().'<input type="hidden" name="act" value="create">';
    echo '<label>Name</label><input name="name" required><label>Company Email</label><input type="email" name="email" required><label>Phone</label><input name="phone">';
    echo '<label>Role</label><select name="role"><option>Admin</option><option>Team Lead</option><option selected>Team Member</option><option>Viewer</option></select>';
    echo '<label>Designation</label><select name="designation">'.desig_options($db).'</select>';
    echo '<label>Department</label><select name="department">'.dept_options($db).'</select>';
    echo '<button type="submit">Create User</button></form></div>';
    echo '<div class="card"><table><tr><th>Name</th><th>Email</th><th>Role</th><th>Designation</th><th>Department</th><th>Active</th><th>Actions</th></tr>';
    foreach ($db->query('SELECT * FROM users ORDER BY id') as $usr) {
        echo '<tr><td>'.e($usr['name']).'</td><td>'.e($usr['email']).'</td><td>'.e($usr['role']).'</td><td>'.e($usr['designation']).'</td><td>'.e($usr['department']).'</td><td>'.((int)$usr['active']?'Yes':'No').'</td><td>';
        if (!(($usr['role']==='Owner') && !is_owner($actor))) {
            echo '<form method="post" style="display:inline">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$usr['id'].'"><button name="act" value="reset">Reset Password</button> <button name="act" value="toggle">Activate/Deactivate</button></form>';
        } else echo '<span class="muted">Owner - protected</span>';
        echo '</td></tr>';
    }
    echo '</table></div>';
    layout_end();
}
function page_org(PDO $db): void {
    $actor=require_role('Admin');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        csrf_check(); $kind=$_POST['kind']==='designations'?'designations':'departments'; $act=$_POST['act']??'';
        if ($act==='add') { $db->prepare("INSERT IGNORE INTO $kind(name) VALUES(?)")->execute([trim($_POST['name'])]); audit($db,ucfirst($kind).' added: '.trim($_POST['name']),'settings',[$kind]); }
        if ($act==='rename') { $old=(string)$_POST['old']; $new=trim($_POST['new']);
            $db->beginTransaction();
            $db->prepare("UPDATE $kind SET name=? WHERE name=?")->execute([$new,$old]);
            if ($kind==='departments') { $db->prepare('UPDATE users SET department=? WHERE department=?')->execute([$new,$old]);
                $db->prepare('UPDATE tickets SET department=? WHERE department=?')->execute([$new,$old]); }
            else { $db->prepare('UPDATE users SET designation=? WHERE designation=?')->execute([$new,$old]); }
            $db->commit();
            audit($db,ucfirst($kind).' renamed: '.$old.' to '.$new,'settings',[$kind]); }
        if ($act==='toggle') { $inUse=false;
            if ($kind==='departments') { $q=$db->prepare('SELECT COUNT(*) FROM tickets WHERE department=?'); $q->execute([$_POST['name']]); $inUse=(int)$q->fetchColumn()>0; }
            $db->prepare("UPDATE $kind SET active=1-active WHERE name=?")->execute([$_POST['name']]);
            audit($db,ucfirst($kind).' deactivated/activated: '.(string)$_POST['name'],'settings',[$kind]); }
        flash('Organization structure updated. Renames apply everywhere, including old tickets and reports.');
        redirect('index.php?page=settings#org');
    }
    layout_start($db,'Organization Structure',$actor);
    echo '<h1>Organization Structure</h1><p class="muted">Works for any industry: an ISP keeps NOC / Fiber Team; a bank can rename NOC to IT Helpdesk - old tickets, filters and reports show the new name. Entries in use cannot be deleted; deactivate them instead.</p>';
    foreach (['departments'=>'Departments','designations'=>'Designations'] as $kind=>$label) {
        echo '<div class="card"><h2>'.e($label).'</h2>';
        foreach ($db->query("SELECT * FROM $kind ORDER BY name") as $row) {
            echo '<p><b>'.e($row['name']).'</b> '.((int)$row['active']?'':'<span class="badge warn">inactive</span>').' ';
            echo '<form method="post" style="display:inline">'.csrf_field().'<input type="hidden" name="kind" value="'.$kind.'"><input type="hidden" name="old" value="'.e($row['name']).'"><input name="new" placeholder="New name" style="width:160px"><button name="act" value="rename">Rename</button></form> ';
            echo '<form method="post" style="display:inline">'.csrf_field().'<input type="hidden" name="kind" value="'.$kind.'"><input type="hidden" name="name" value="'.e($row['name']).'"><button name="act" value="toggle">Deactivate/Activate</button></form></p>';
        }
        echo '<form method="post">'.csrf_field().'<input type="hidden" name="kind" value="'.$kind.'"><input name="name" placeholder="Add new" required style="width:220px"><button name="act" value="add">Add</button></form></div>';
    }
    layout_end();
}
