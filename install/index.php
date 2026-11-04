<?php
declare(strict_types=1);
/* OpenDesk CRM - first-run installer wizard.
   Checks the PHP version, creates the (empty) database schema, and writes config/config.php.
   It creates NO account and NO data. The Owner account is created afterwards, once,
   inside the application itself. Target runtime: PHP 8.5.12 (latest patched). */
$root=dirname(__DIR__);
$cfgFile=$root.'/config/config.php';
$phpOk=version_compare(PHP_VERSION,'8.5.0','>=');
$phpRuns=version_compare(PHP_VERSION,'8.1.0','>=');
$errors=[]; $done=false;
if (is_file($cfgFile)) { $errors[]='Already installed (config/config.php exists). Delete that file only if you intend a fresh, empty install.'; }
if ($_SERVER['REQUEST_METHOD']==='POST' && !$errors) {
    $host=trim($_POST['db_host']??'127.0.0.1'); $port=(int)($_POST['db_port']??3306);
    $name=trim($_POST['db_name']??''); $user=trim($_POST['db_user']??''); $pass=(string)($_POST['db_pass']??'');
    if ($name===''||$user==='') $errors[]='Database name and user are required.';
    if (!$errors) {
        try {
            $pdo=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.str_replace('`','',$name).'` CHARACTER SET utf8mb4');
            $pdo->exec('USE `'.str_replace('`','',$name).'`');
            $sql=file_get_contents(__DIR__.'/schema.sql');
            foreach (array_filter(array_map('trim', explode(';',$sql))) as $stmt) {
                if (str_starts_with($stmt,'--')) { $stmt=preg_replace('/^--.*$/m','',$stmt); if (trim($stmt)==='') continue; }
                $pdo->exec($stmt);
            }
            $cfg="<?php\n// Written by the installer. Keep this file private; it is protected by .htaccess.\nreturn [\n 'db_host'=>".var_export($host,true).",\n 'db_port'=>$port,\n 'db_name'=>".var_export($name,true).",\n 'db_user'=>".var_export($user,true).",\n 'db_pass'=>".var_export($pass,true).",\n 'timezone'=>'Asia/Karachi',\n 'session_timeout'=>1800,\n];\n";
            if (file_put_contents($cfgFile,$cfg)===false) $errors[]='Could not write config/config.php - check folder permissions.';
            else $done=true;
        } catch (Throwable $ex) { $errors[]='Database error: '.$ex->getMessage(); }
    }
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Install - OpenDesk CRM</title>
<link rel="stylesheet" href="../public/assets/style.css"></head><body><main>
<div class="card" style="max-width:640px;margin:5vh auto">
<h1>OpenDesk CRM - Installer</h1>
<p>PHP version detected: <b><?= htmlspecialchars(PHP_VERSION) ?></b><br>
<?php if ($phpOk): ?><span class="badge okb">Compatible with the PHP 8.5.12 target</span>
<?php elseif ($phpRuns): ?><span class="badge warn">Runs, but PHP 8.5.12 (latest patched) is recommended</span>
<?php else: ?><span class="badge bad">PHP 8.1+ is required</span><?php endif; ?></p>
<p class="muted">This installer creates EMPTY tables only - no accounts, no customers, no tickets.
After installation, the first screen creates the one-time Owner account.</p>
<?php foreach ($errors as $er): ?><div class="flash err"><?= htmlspecialchars($er) ?></div><?php endforeach; ?>
<?php if ($done): ?><div class="flash ok">Database created (empty) and configuration written.</div>
<p><a class="btn" href="../public/index.php">Open OpenDesk CRM &amp; create the Owner account</a></p>
<?php elseif (!$errors || $_SERVER['REQUEST_METHOD']==='POST'): ?>
<form method="post">
<label>Database Host</label><input name="db_host" value="127.0.0.1" required>
<label>Database Port</label><input name="db_port" value="3306" required>
<label>Database Name</label><input name="db_name" required placeholder="opendesk_crm">
<label>Database User</label><input name="db_user" required>
<label>Database Password</label><input type="password" name="db_pass">
<button type="submit">Install (create empty database)</button></form>
<?php endif; ?>
</div></main>
<footer class="app-footer">Powered by Technical RHU &middot; Owned by Raja Huwaris Usmani</footer>
</body></html>
