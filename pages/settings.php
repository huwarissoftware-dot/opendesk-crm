<?php
declare(strict_types=1);
/* Settings. Branding + Network Configuration + ownership are OWNER-ONLY.
   Email integration, SLA targets and general settings: Owner/Admin per page guards. */
function save_upload_file(string $field, string $prefix): ?string {
    if (empty($_FILES[$field]['name'])) return null;
    $ext=strtolower(pathinfo($_FILES[$field]['name'],PATHINFO_EXTENSION));
    if (!in_array($ext,['png','jpg','jpeg','webp','svg'],true)) return null;
    $dest=dirname(__DIR__).'/public/assets/'.$prefix.'.'.$ext;
    move_uploaded_file($_FILES[$field]['tmp_name'],$dest);
    return 'assets/'.$prefix.'.'.$ext;
}
function page_settings(PDO $db): void {
    $actor=require_role('Admin');
    $owner=is_owner($actor);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        csrf_check(); $scope=(string)($_POST['scope']??'general');
        if ($scope==='branding' || $scope==='network') {
            if (!$owner) { http_response_code(403); exit('Branding and Network Configuration are Owner-only settings.'); }
        }
        if ($scope==='branding') {
            $logo=save_upload_file('logo','logo'); if ($logo) set_setting($db,'logo_path',$logo);
            $wm=save_upload_file('watermark','watermark'); if ($wm) set_setting($db,'watermark_path',$wm);
            if (!empty($_POST['remove_watermark'])) set_setting($db,'watermark_path',null);
            audit($db,'Branding updated','settings',['logo','watermark']);
        } elseif ($scope==='network') {
            foreach (['org_name','lan_address','public_ip','public_domain','external_access','access_mode','allowed_ips','session_timeout','timezone'] as $k) {
                if (isset($_POST[$k])) set_setting($db,$k,trim((string)$_POST[$k]));
            }
            audit($db,'Network configuration updated','settings',['network']);
        } elseif ($scope==='email') {
            /* Mail ports are FULLY EDITABLE: any number 1-65535 is accepted for both
               IMAP and SMTP; validation rejects only out-of-range numbers. Security is
               a separate selector per direction (ssl | starttls | none). */
            $imapPort=(int)($_POST['imap_port']??993); $smtpPort=(int)($_POST['smtp_port']??587);
            $badPort=null;
            if ($imapPort<1 || $imapPort>65535) $badPort='IMAP';
            if ($smtpPort<1 || $smtpPort>65535) $badPort='SMTP';
            if (($_POST['act']??'')==='test') {
                // Test Connection reports against the ENTERED values (saved or not).
                require_once dirname(__DIR__).'/app/mailer.php';
                flash(mail_connection_test('imap', trim((string)($_POST['imap_host']??'')), $imapPort, (string)($_POST['imap_security']??'ssl')));
                flash(mail_connection_test('smtp', trim((string)($_POST['smtp_host']??'')), $smtpPort, (string)($_POST['smtp_security']??'starttls')));
                redirect('index.php?page=settings');
            }
            if ($badPort) { flash($badPort.' port must be a number between 1 and 65535 - nothing was saved.','err'); redirect('index.php?page=settings'); }
            foreach (['mail_preset','imap_host','imap_port','imap_security','imap_user','smtp_host','smtp_port','smtp_security','smtp_user','smtp_from','mail_poll_minutes','monitoring_token','whatsapp_enabled','whatsapp_phone_id','whatsapp_token'] as $k) {
                if (isset($_POST[$k])) set_setting($db,$k,trim((string)$_POST[$k]));
            }
            if (!empty($_POST['imap_pass'])) set_setting($db,'imap_pass',(string)$_POST['imap_pass']); // stored, never shown again
            if (!empty($_POST['smtp_pass'])) set_setting($db,'smtp_pass',(string)$_POST['smtp_pass']);
            audit($db,'Email integration updated','settings',['email']); // passwords are never written to the audit log
        } elseif ($scope==='general') {
            foreach (['sla_critical','sla_high','sla_medium','sla_low','normal_deployment_update_days'] as $k) {
                if (isset($_POST[$k])) set_setting($db,$k,trim((string)$_POST[$k]));
            }
            audit($db,'General settings updated','settings',['sla','deployment_days']);
        }
        flash('Settings saved.'); redirect('index.php?page=settings');
    }
    layout_start($db,'Settings',$actor);
    echo '<h1>Settings</h1>';
    echo '<p><a class="btn secondary" href="index.php?page=org">Organization Structure (Departments &amp; Designations)</a> ';
    echo '<a class="btn secondary" href="index.php?page=backup">Create Backup Now</a></p>';
    // Deployment information
    echo '<div class="card"><h2>Deployment Information</h2><p>PHP version: <b>'.e(PHP_VERSION).'</b> ';
    echo version_compare(PHP_VERSION,'8.5.0','>=')?'<span class="badge okb">PHP 8.5.12 target - compatible</span>':'<span class="badge warn">PHP 8.5.12 (latest patched) recommended</span>';
    try { $db->query('SELECT 1'); echo '<br>Database: <span class="badge okb">Connected</span>'; } catch (Throwable) { echo '<br>Database: <span class="badge bad">Error</span>'; }
    $upBytes=0; foreach (glob(dirname(__DIR__).'/storage/uploads/*')?:[] as $f) $upBytes+=filesize($f);
    echo '<br>Uploads storage used: '.number_format($upBytes/1048576,2).' MB</p></div>';
    if ($owner) {
        echo '<div class="card"><h2>Branding (Owner only)</h2><form method="post" enctype="multipart/form-data">'.csrf_field().'<input type="hidden" name="scope" value="branding">';
        echo '<label>Header Logo (shown at the top of every screen)</label><input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg">';
        echo '<label>Background Watermark Logo (separate - large, centered, ~10% opacity, blurred, behind every screen including Login)</label><input type="file" name="watermark" accept=".png,.jpg,.jpeg,.webp,.svg">';
        echo '<label><input type="checkbox" name="remove_watermark" style="width:auto"> Remove watermark logo</label><br>';
        echo '<button type="submit">Save Branding</button></form></div>';
        echo '<div class="card"><h2>Network Configuration (Owner only)</h2><form method="post">'.csrf_field().'<input type="hidden" name="scope" value="network">';
        $f=function(string $k,string $label,string $ph='') use ($db){ echo '<label>'.$label.'</label><input name="'.$k.'" value="'.e(setting($db,$k)??'').'" placeholder="'.e($ph).'">'; };
        $f('org_name','Organization Name'); $f('lan_address','LAN Address','http://192.168.1.10');
        $f('public_ip','Public Static IP','203.0.113.10'); $f('public_domain','Public Domain','crm.example.com');
        echo '<label>External Access</label><select name="external_access"><option value="1"'.(setting($db,'external_access')==='1'?' selected':'').'>On</option><option value="0"'.(setting($db,'external_access')==='0'?' selected':'').'>Off</option></select>';
        echo '<label>Access Mode</label><select name="access_mode"><option value="open"'.(setting($db,'access_mode')==='open'?' selected':'').'>Open with Login (anywhere in the world, account required)</option><option value="restricted"'.(setting($db,'access_mode')==='restricted'?' selected':'').'>Restricted by IP (only listed public IPs)</option></select>';
        $f('allowed_ips','Allowed Public IPs / CIDR (comma separated)','203.0.113.10, 198.51.100.0/24');
        $f('session_timeout','Session Timeout (seconds)','1800'); $f('timezone','Timezone','Asia/Karachi');
        echo '<p class="muted">Safety rule: the Owner is never locked out from the LAN address.</p>';
        echo '<button type="submit">Save Network Configuration</button></form>';
        echo '<h3>Deployment Checklist (step by step)</h3><ol>
        <li>Prepare the server: PHP 8.5.12, Apache or Nginx, MySQL / MariaDB.</li>
        <li>Upload these files, set write permissions on config/, storage/uploads/ and storage/backups/.</li>
        <li>Open /install/ in a browser, run the installer, then create the Owner account (only the Owner).</li>
        <li>Give the server a fixed LAN IP and test inside the office.</li>
        <li>On the router/firewall, port-forward 443 (and 80 for redirect/certificate) from the Public Static IP to the server LAN IP.</li>
        <li>Optional: point the domain A record to the Public Static IP.</li>
        <li>Install an SSL certificate and force HTTPS - mandatory for access from outside.</li>
        <li>Set the Public IP/Domain and Access Mode on this page.</li>
        <li>Add cron entries for mail fetching, the daily report and backups (see README).</li>
        <li>Create staff accounts (Admin at most) and verify senior management can log in from outside and view remarks.</li></ol></div>';
    }
    echo '<div class="card"><h2>Email Integration</h2><form method="post">'.csrf_field().'<input type="hidden" name="scope" value="email">';
    $preset=setting($db,'mail_preset','imap');
    echo '<label>Source Preset</label><select name="mail_preset" id="mailPreset">';
    echo '<option value="m365"'.($preset==='m365'?' selected':'').'>Microsoft 365 (suggests IMAP 993 SSL, SMTP 587 STARTTLS)</option>';
    echo '<option value="imap"'.($preset==='imap'?' selected':'').'>Generic IMAP (any server - you type the ports your server uses)</option>';
    echo '<option value="zimbra"'.($preset==='zimbra'?' selected':'').'>Zimbra Server (suggests IMAP 993 SSL, SMTP 465 SSL; 587 STARTTLS is the alternative)</option></select>';
    echo '<p class="muted" id="presetHelp"></p>';
    echo '<p class="muted"><b>Ports are fully editable.</b> A preset only pre-fills the common values - type whatever port your own server uses (any number 1-65535) and pick the matching Security mode for each direction. Nothing is locked. Common values: IMAP 993 (SSL) or 143 (plain / STARTTLS); SMTP 465 (SSL), 587 (STARTTLS / submission) or 25 (plain).</p>';
    $f=function(string $k,string $label) use ($db){ echo '<label>'.$label.'</label><input name="'.$k.'" value="'.e(setting($db,$k)??'').'">'; };
    $portField=function(string $k,string $label,string $id) use ($db){ echo '<label>'.$label.'</label><input type="number" min="1" max="65535" step="1" required name="'.$k.'" id="'.$id.'" value="'.e(setting($db,$k)??'').'">'; };
    $secField=function(string $k,string $label,string $id,string $def) use ($db){ $cur=setting($db,$k,$def)?:$def;
        echo '<label>'.$label.'</label><select name="'.$k.'" id="'.$id.'">';
        foreach (['ssl'=>'SSL / TLS (direct encrypted connection)','starttls'=>'STARTTLS (plain connection, upgraded to TLS)','none'=>'None (plain, no encryption)'] as $v=>$t) echo '<option value="'.$v.'"'.($cur===$v?' selected':'').'>'.$t.'</option>';
        echo '</select>'; };
    $f('imap_host','IMAP Host (e.g. mail.your-company.com)');
    $portField('imap_port','IMAP Port - editable, any value 1-65535 (preset suggests 993 for SSL, 143 for plain/STARTTLS)','imapPort');
    $secField('imap_security','IMAP Security','imapSecurity','ssl');
    $f('imap_user','Support Email ID (mailbox user)');
    echo '<label>Mailbox Password (stored securely, never shown again)</label><input type="password" name="imap_pass" placeholder="Leave blank to keep current">';
    $f('smtp_host','SMTP Host (outgoing)');
    $portField('smtp_port','SMTP Port - editable, any value 1-65535 (preset suggests 465 for SSL, 587 for STARTTLS, 25 plain)','smtpPort');
    $secField('smtp_security','SMTP Security','smtpSecurity','starttls');
    $f('smtp_user','SMTP User'); $f('smtp_from','SMTP From Address');
    echo '<label>SMTP Password (never shown again)</label><input type="password" name="smtp_pass" placeholder="Leave blank to keep current">';
    $f('mail_poll_minutes','Check mailbox every (minutes)'); $f('monitoring_token','Monitoring Webhook Token');
    echo '<label>WhatsApp Alerts (Meta Cloud API - optional)</label><select name="whatsapp_enabled"><option value="0">Off</option><option value="1"'.(setting($db,'whatsapp_enabled')==='1'?' selected':'').'>On</option></select>';
    $f('whatsapp_phone_id','WhatsApp Phone Number ID'); $f('whatsapp_token','WhatsApp API Token');
    echo '<button type="submit">Save Email Integration</button> ';
    echo '<button type="submit" name="act" value="test" class="btn secondary" formnovalidate>Test Connection (uses the values entered above)</button></form>';
    echo '<p class="muted">Cron fetches mail read-only (it never deletes or moves mail), deduplicates by Message-ID, matches the sender to a customer, appends replies carrying a ticket reference to that ticket, and sends unmatched mail to Needs Review on the Tickets list.</p></div>';
    echo '<div class="card"><h2>SLA Targets &amp; Deployment</h2><form method="post">'.csrf_field().'<input type="hidden" name="scope" value="general">';
    foreach (['sla_critical'=>'Critical (hours)','sla_high'=>'High (hours)','sla_medium'=>'Medium (hours)','sla_low'=>'Low (hours)','normal_deployment_update_days'=>'Normal deployment: ask assignee for update after (days, 8-10)'] as $k=>$label) {
        echo '<label>'.$label.'</label><input name="'.$k.'" value="'.e(setting($db,$k)??'').'">';
    }
    echo '<button type="submit">Save</button></form></div>';
    echo '<script>var P={m365:{h:"Microsoft 365: IMAP outlook.office365.com port 993 SSL; SMTP smtp.office365.com port 587 STARTTLS. These are suggestions only - both ports stay fully editable.",ip:993,is:"ssl",sp:587,ss:"starttls"},imap:{h:"Generic IMAP: type your own server host and whatever ports it uses - IMAP 993 SSL or 143 plain/STARTTLS are the common ones; SMTP 465 SSL, 587 STARTTLS or 25 plain. Nothing is locked.",ip:993,is:"ssl",sp:587,ss:"starttls"},zimbra:{h:"Zimbra: enter your Zimbra server address (e.g. mail.your-company.com). Suggested values pre-filled: IMAP port 993 SSL, SMTP port 465 SSL (587 STARTTLS is the noted alternative - change it if your Zimbra uses 587). Mail is picked up directly from the Zimbra server - Outlook only reads the same mailbox.",ip:993,is:"ssl",sp:465,ss:"ssl"}};var s=document.getElementById("mailPreset");function upd(){var p=P[s.value];if(!p)return;document.getElementById("presetHelp").textContent=p.h;document.getElementById("imapPort").value=p.ip;document.getElementById("imapSecurity").value=p.is;document.getElementById("smtpPort").value=p.sp;document.getElementById("smtpSecurity").value=p.ss;}s.addEventListener("change",upd);document.getElementById("presetHelp").textContent=(P[s.value]||{}).h||"";</script>';
    layout_end();
}
function page_backup(PDO $db): void {
    $actor=require_role('Admin');
    audit($db,'Backup created (manual)','system',['backup']);
    $dir=dirname(__DIR__).'/storage/backups'; $file=$dir.'/backup-'.date('Ymd-His').'.sql';
    $dump="-- OpenDesk CRM backup ".date('c')."\n";
    foreach (['users','customers','vendors','tickets','remarks','attachments','approvals','audit_log','departments','designations','settings','colo_sites','colo_visits','stock_items','kb_articles'] as $tbl) {
        foreach ($db->query("SELECT * FROM $tbl") as $row) {
            $vals=array_map(fn($v)=>$v===null?'NULL':$db->quote((string)$v), array_values($row));
            $dump.="INSERT INTO $tbl (`".implode('`,`',array_keys($row))."`) VALUES (".implode(',',$vals).");\n";
        }
    }
    file_put_contents($file,$dump);
    layout_start($db,'Backup',$actor);
    echo '<div class="card"><h1>Backup Created</h1><p>Saved to storage/backups/ on the server: <b>'.e(basename($file)).'</b></p><p class="muted">For automatic daily backups, add the cron entry for cron/backup.php (see README).</p></div>';
    layout_end();
}
