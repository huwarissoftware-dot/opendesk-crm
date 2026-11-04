<?php
declare(strict_types=1);
/* Authentication, sessions and role hierarchy: Owner > Admin > Team Lead > Team Member > Viewer. */
const ROLE_RANK=['Viewer'=>1,'Team Member'=>2,'Team Lead'=>3,'Admin'=>4,'Owner'=>5];
function session_boot(array $cfg=[]): void {
    $timeout=(int)($cfg['session_timeout']??1800);
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])]);
    session_start();
    if (!empty($_SESSION['last']) && (time()-$_SESSION['last'])>$timeout && !empty($_SESSION['user'])) {
        session_unset();
        $_SESSION['flash']=[['err','Session expired. Please log in again.']];
    }
    $_SESSION['last']=time();
}
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): array {
    $u=current_user();
    if (!$u) redirect('index.php?page=login');
    if (!empty($u['force_change'])) redirect('index.php?page=change_password');
    return $u;
}
function require_role(string $min): array {
    $u=require_login();
    if ((ROLE_RANK[$u['role']]??0) < (ROLE_RANK[$min]??99)) { http_response_code(403); exit('Access denied for your role.'); }
    return $u;
}
function is_owner(array $u): bool { return ($u['role']??'')==='Owner'; }
function can_manage_users(array $u): bool { return in_array($u['role']??'',['Owner','Admin'],true); }
/* Owner-protection rules, enforced server-side:
   - Nobody can create or grant a second Owner.
   - An Admin cannot edit, reset, deactivate or delete the Owner.
   - Owner-only settings (branding, network, ownership) check is_owner(). */
function guard_owner_target(array $actor, array $target): void {
    if (($target['role']??'')==='Owner' && !is_owner($actor)) { http_response_code(403); exit('The Owner account can only be managed by the Owner.'); }
}
function attempt_login(PDO $db, string $email, string $password): ?array {
    $st=$db->prepare('SELECT * FROM users WHERE email=? AND active=1'); $st->execute([$email]);
    $u=$st->fetch();
    if (!$u || !password_verify($password,(string)$u['password_hash'])) return null;
    unset($u['password_hash']);
    return $u;
}
function hash_password(string $pw): string {
    return password_hash($pw, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
}
