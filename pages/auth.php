<?php
declare(strict_types=1);
/* Setup Owner (once), Login, Logout, Change Password. */
function page_setup_owner(PDO $db): void {
    $count=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count>0) redirect('index.php?page=login'); // Owner setup is one-time only.
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        csrf_check();
        $name=trim($_POST['name']??''); $email=trim($_POST['email']??''); $pw=(string)($_POST['password']??'');
        if ($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pw)<10) {
            flash('Name, a valid company email, and a password of at least 10 characters are required.','err');
        } else {
            $depts=$db->query('SELECT name FROM departments WHERE active=1 ORDER BY id LIMIT 1')->fetchColumn();
            $st=$db->prepare('INSERT INTO users(name,email,password_hash,role,designation,department) VALUES(?,?,?,?,?,?)');
            $st->execute([$name,$email,hash_password($pw),'Owner','Owner',(string)$depts]);
            audit($db,'Owner account created','users',['email'],['name'=>$name,'email'=>$email,'department'=>(string)$depts]);
            flash('Owner account created. Please log in.');
            redirect('index.php?page=login');
        }
    }
    layout_start($db,'Create Owner Account',null);
    echo '<div class="login-wrap"><div class="card"><h1>Create Owner Account</h1>';
    echo '<p class="muted">One-time setup. This is the single Owner account - full rights stay with the Owner. There is no default password; the password you set here is yours alone.</p>';
    echo '<form method="post">'.csrf_field();
    echo '<label>Full Name</label><input name="name" required>';
    echo '<label>Company Email</label><input type="email" name="email" required>';
    echo '<label>Password (min 10 characters)</label><input type="password" name="password" required>';
    echo '<button type="submit">Create Owner Account</button></form></div></div>';
    layout_end();
}
function access_blocked(PDO $db): ?string {
    if (setting($db,'external_access','1')!=='1') {
        $lan=setting($db,'lan_address','');
        if ($lan && !str_contains($lan, client_ip()) && client_ip()!=='127.0.0.1') return 'External access is disabled in Network Configuration.';
    }
    if (setting($db,'access_mode','open')==='restricted') {
        $list=setting($db,'allowed_ips','') ?: '';
        $lan=setting($db,'lan_address','');
        $isLan=$lan && str_starts_with(client_ip(), preg_replace('/\.\d+$/','.', preg_replace('#^https?://#','',(string)$lan)));
        if (!$isLan && !ip_allowed(client_ip(),$list) && client_ip()!=='127.0.0.1') return 'Your IP address is not in the allowed list (Network Configuration). The Owner is never locked out from the LAN address.';
    }
    return null;
}
function page_login(PDO $db): void {
    if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===0) redirect('index.php?page=setup_owner');
    if (current_user()) redirect('index.php?page=dashboard');
    $blocked=access_blocked($db);
    if ($_SERVER['REQUEST_METHOD']==='POST' && !$blocked) {
        csrf_check();
        $u=attempt_login($db, trim($_POST['email']??''), (string)($_POST['password']??''));
        if ($u) {
            session_regenerate_id(true);
            $_SESSION['user']=$u;
            audit($db,'Login','users',[],$u);
            redirect(!empty($u['force_change'])?'index.php?page=change_password':'index.php?page=dashboard');
        }
        flash('Invalid email or password.','err');
    }
    layout_start($db,'Login',null);
    echo '<div class="login-wrap"><div class="card"><h1>'.e(org_name($db)).'</h1>';
    if ($blocked) echo '<div class="flash err">'.e($blocked).'</div>';
    echo '<form method="post">'.csrf_field();
    echo '<label>Email</label><input type="email" name="email" required>';
    echo '<label>Password</label><input type="password" name="password" required>';
    echo '<button type="submit">Login</button></form></div></div>';
    layout_end();
}
function page_logout(PDO $db): void { $_SESSION=[]; session_destroy(); header('Location: index.php?page=login'); exit; }
function page_change_password(PDO $db): void {
    $u=current_user(); if (!$u) redirect('index.php?page=login');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        csrf_check();
        $st=$db->prepare('SELECT password_hash FROM users WHERE id=?'); $st->execute([(int)$u['id']]);
        $hash=(string)$st->fetchColumn();
        $new=(string)($_POST['new_password']??'');
        if (!password_verify((string)($_POST['current_password']??''),$hash)) flash('Current password is incorrect.','err');
        elseif (strlen($new)<10) flash('New password must be at least 10 characters.','err');
        else {
            $db->prepare('UPDATE users SET password_hash=?, force_change=0 WHERE id=?')->execute([hash_password($new),(int)$u['id']]);
            $_SESSION['user']['force_change']=0;
            audit($db,'Password changed','users',[],$u);
            flash('Password changed.'); redirect('index.php?page=dashboard');
        }
    }
    layout_start($db,'Change Password',$u);
    echo '<div class="card"><h1>Change Password</h1><form method="post">'.csrf_field();
    echo '<label>Current Password</label><input type="password" name="current_password" required>';
    echo '<label>New Password (min 10 characters)</label><input type="password" name="new_password" required>';
    echo '<button type="submit">Change Password</button></form></div>';
    layout_end();
}
