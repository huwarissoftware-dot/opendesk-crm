<?php
declare(strict_types=1);
/* Outgoing mail through the configured SMTP (incl. Zimbra SMTP). Uses PHP mail transport
   via stream sockets - no external dependencies. Customer mails carry the ticket
   reference in the subject and only current status (+ ETR when known) in the body. */
function smtp_send(PDO $db, string $to, string $subject, string $body): bool|string {
    $host=setting($db,'smtp_host');
    /* The SMTP port is FULLY EDITABLE (any value 1-65535) and the Security mode is a
       separate selector (ssl | starttls | none). The saved combination is used exactly
       as entered - no port is ever locked to a fixed security mode. */
    $port=(int)(setting($db,'smtp_port','587')?:587);
    $security=strtolower((string)(setting($db,'smtp_security','starttls')?:'starttls'));
    $user=setting($db,'smtp_user'); $pass=setting($db,'smtp_pass'); $from=setting($db,'smtp_from') ?: $user;
    if (!$host || !$from) return 'SMTP is not configured in Settings > Email Integration.';
    if ($port<1 || $port>65535) return 'SMTP port must be a number between 1 and 65535.';
    $ctx=stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    $remote=($security==='ssl'?'ssl://':'tcp://').$host.':'.$port;
    $fp=@stream_socket_client($remote,$errno,$errstr,20,STREAM_CLIENT_CONNECT,$ctx);
    if (!$fp) return 'SMTP connect failed ('.$host.':'.$port.', security: '.$security.'): '.$errstr;
    $read=static fn()=>fgets($fp,512);
    $cmd=static function(string $c) use ($fp){ fwrite($fp,$c."\r\n"); };
    $read();
    if ($security==='starttls') { $cmd('EHLO opendesk'); $read(); $cmd('STARTTLS'); $read();
        stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT); }
    $cmd('EHLO opendesk'); $read();
    if ($user) { $cmd('AUTH LOGIN'); $read(); $cmd(base64_encode($user)); $read(); $cmd(base64_encode((string)$pass)); $r=$read(); if (!str_starts_with((string)$r,'235')) { fclose($fp); return 'SMTP authentication failed.'; } }
    $cmd('MAIL FROM: <'.$from.'>'); $read();
    $cmd('RCPT TO: <'.$to.'>'); $read();
    $cmd('DATA'); $read();
    $msg="From: $from\r\nTo: $to\r\nSubject: $subject\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$body\r\n.";
    $cmd($msg); $read(); $cmd('QUIT'); fclose($fp);
    return true;
}
/* Test a mail connection against the EXACT values entered (host, any port 1-65535,
   and the security mode ssl | starttls | none). Used by Settings > Test Connection.
   It reports against the entered values whether or not they have been saved yet. */
function mail_connection_test(string $kind, string $host, int $port, string $security): string {
    $label=($kind==='imap'?'IMAP':'SMTP').' '.$host.':'.$port.' ('.$security.')';
    if ($host==='') return $label.': not tested - no host entered.';
    if ($port<1 || $port>65535) return $label.': FAILED - port must be a number between 1 and 65535.';
    $ctx=stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    $remote=($security==='ssl'?'ssl://':'tcp://').$host.':'.$port;
    $fp=@stream_socket_client($remote,$errno,$errstr,10,STREAM_CLIENT_CONNECT,$ctx);
    if (!$fp) return $label.': FAILED - '.$errstr;
    stream_set_timeout($fp,10);
    $greeting=trim((string)fgets($fp,512));
    if ($security==='starttls') {
        if ($kind==='smtp') {
            fwrite($fp,"EHLO opendesk\r\n"); fgets($fp,512);
            fwrite($fp,"STARTTLS\r\n"); $r=(string)fgets($fp,512);
            if (!str_starts_with($r,'220')) { fclose($fp); return $label.': FAILED - server refused STARTTLS ('.trim($r).')'; }
        } else {
            fwrite($fp,"a1 STARTTLS\r\n"); $r=(string)fgets($fp,512);
            if (!str_contains($r,'a1 OK')) { fclose($fp); return $label.': FAILED - server refused STARTTLS ('.trim($r).')'; }
        }
        if (!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return $label.': FAILED - TLS upgrade failed.'; }
    }
    fclose($fp);
    return $label.': OK - connected. Server greeting: '.$greeting;
}
function notify_customer(PDO $db, array $ticket, string $event): void {
    if (empty($ticket['customer_email'])) return;
    $ref=$ticket['reference'];
    $status=(string)$ticket['status'];
    $body="Dear Customer,\n\nYour ticket $ref is now: $status.";
    if (!empty($ticket['etr'])) $body.="\nExpected resolution time (ETR): ".$ticket['etr'];
    $body.="\n\nThank you.";
    $subj='['.$ref.'] '.($event==='resolved'?'Ticket Resolved':'Ticket Update');
    smtp_send($db,(string)$ticket['customer_email'],$subj,$body);
}
