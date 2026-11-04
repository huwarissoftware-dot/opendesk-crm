<?php
declare(strict_types=1);
/* OpenDesk CRM - core helpers. */
function e(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        http_response_code(419); exit('CSRF token mismatch.');
    }
}
function redirect(string $url): never { header('Location: '.$url); exit; }
function flash(string $msg, string $type='ok'): void { $_SESSION['flash'][] = [$type,$msg]; }
function flashes(): array { $f=$_SESSION['flash']??[]; unset($_SESSION['flash']); return $f; }
/* STRICT RULE: The footer credit below is hard-coded in the application core.
   It is NOT a setting, NOT stored in the database, and there is intentionally
   NO admin option to edit, hide or remove it. See LICENSE. */
function app_footer_credit(): string {
    return '<footer class="app-footer">Powered by Technical RHU &middot; Owned by Raja Huwaris Usmani</footer>';
}
function setting(PDO $db, string $key, ?string $default=null): ?string {
    $st=$db->prepare('SELECT v FROM settings WHERE k=?'); $st->execute([$key]);
    $r=$st->fetchColumn(); return $r===false ? $default : (string)$r;
}
function set_setting(PDO $db, string $key, ?string $value): void {
    $st=$db->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
    $st->execute([$key,$value]);
}
function org_name(PDO $db): string { return setting($db,'org_name','OpenDesk CRM') ?: 'OpenDesk CRM'; }
function audit(PDO $db, string $action, string $target='', array $fields=[], ?array $actor=null): void {
    $u=$actor ?? ($_SESSION['user'] ?? null);
    $name=$u['name']??'System'; $email=$u['email']??''; $dept=$u['department']??'';
    $st=$db->prepare('INSERT INTO audit_log(actor_name,actor_email,actor_department,action,target,changed_fields,created_at) VALUES(?,?,?,?,?,?,NOW())');
    $st->execute([$name,$email,$dept,$action,$target,implode(', ', $fields)]);
}
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function ip_allowed(string $ip, string $listCsv): bool {
    foreach (array_filter(array_map('trim', explode(',', $listCsv))) as $rule) {
        if (str_contains($rule,'/')) {
            [$net,$bits]=$rule+['', '32']; $bits=(int)($rule ? explode('/',$rule)[1] : 32);
            $net=explode('/',$rule)[0];
            $mask=$bits===0?0:(-1 << (32-$bits));
            if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) return true;
        } elseif ($ip===$rule) return true;
    }
    return false;
}
