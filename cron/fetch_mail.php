<?php
declare(strict_types=1);
/* Cron: poll the support mailbox READ-ONLY and turn mail into tickets.
   Run every 5 minutes (see README for the cron line). Works with the Microsoft 365,
   Generic IMAP and Zimbra presets - all are standard IMAP (Zimbra included).
   Rules: never delete/move mail; deduplicate by Message-ID; sender matched to a
   customer by email/domain; a subject carrying an existing ticket reference is
   appended to that ticket as an update; unmatched mail becomes a ticket in the
   visible "Needs Review" state (pending at Online Technical Support, requirement
   prefixed). Everything is audit-logged as System (Email). Requires ext-imap. */
$root=dirname(__DIR__);
require $root.'/app/core.php'; require $root.'/app/db.php'; require $root.'/app/tickets.php'; require $root.'/app/mailer.php';
$db=db();
$host=setting($db,'imap_host');
/* The IMAP port is FULLY EDITABLE (any value 1-65535) and the Security mode is a
   separate selector (ssl | starttls | none). The saved combination is used exactly
   as entered - no port is ever locked to a fixed security mode. */
$port=(int)(setting($db,'imap_port','993')?:993);
$security=strtolower((string)(setting($db,'imap_security','ssl')?:'ssl'));
$user=setting($db,'imap_user'); $pass=setting($db,'imap_pass');
if (!$host||!$user||!$pass) { fwrite(STDERR,"IMAP not configured.\n"); exit(1); }
if ($port<1 || $port>65535) { fwrite(STDERR,"IMAP port must be a number between 1 and 65535.\n"); exit(1); }
if (!function_exists('imap_open')) { fwrite(STDERR,"PHP extension ext-imap is required.\n"); exit(1); }
$flags=match($security) {
    'ssl' => '/imap/ssl/novalidate-cert',
    'starttls' => '/imap/tls/novalidate-cert',
    default => '/imap/notls/novalidate-cert',
};
$box=@imap_open('{'.$host.':'.$port.$flags.'}INBOX',$user,$pass,OP_READONLY);
if (!$box) { fwrite(STDERR,'IMAP open failed: '.imap_last_error()."\n"); exit(1); }
$msgs=imap_search($box,'UNSEEN') ?: [];
$sys=['name'=>'System (Email)','email'=>'','department'=>'System'];
foreach ($msgs as $no) {
    $hdr=imap_headerinfo($box,$no);
    $mid=trim((string)($hdr->message_id??''), '<>');
    if ($mid==='') continue;
    $q=$db->prepare('SELECT COUNT(*) FROM processed_emails WHERE message_id=?'); $q->execute([$mid]);
    if ((int)$q->fetchColumn()>0) continue; // deduplicated
    $from=strtolower((string)($hdr->from[0]->mailbox??'').'@'.(string)($hdr->from[0]->host??''));
    $subject=(string)($hdr->subject??'No subject');
    $body=(string)(imap_body($box,$no) ?: '');
    $ticketId=null;
    if (preg_match('/([A-Za-z0-9 .&\'-]+-\d{8}-\d+)/',$subject,$m)) {
        $tq=$db->prepare('SELECT id FROM tickets WHERE reference=?'); $tq->execute([$m[1]]);
        $ticketId=$tq->fetchColumn() ?: null;
    }
    if ($ticketId) {
        $db->prepare('INSERT INTO remarks(ticket_id,body,update_type,author_name,author_department) VALUES(?,?,?,?,?)')
           ->execute([(int)$ticketId,'Email update: '.$subject."\n\n".mb_strimwidth($body,0,4000),'Customer Update','System (Email)','System']);
        audit($db,'Email reply appended to ticket',(string)$ticketId,[],$sys);
    } else {
        $cq=$db->prepare('SELECT * FROM customers WHERE email=?'); $cq->execute([$from]); $cust=$cq->fetch();
        if (!$cust) { $dom=substr(strrchr($from,'@'),1);
            $cq=$db->prepare('SELECT * FROM customers WHERE email LIKE ?'); $cq->execute(['%@'.$dom]); $cust=$cq->fetch(); }
        $needsReview=!$cust;
        if (!$cust) { $db->prepare('INSERT INTO customers(company,email) VALUES(?,?)')->execute([$from,$from]); $cust=['id'=>(int)$db->lastInsertId(),'company'=>$from,'email'=>$from]; }
        $db->beginTransaction();
        $ref=next_ticket_reference($db,$cust);
        $db->prepare('INSERT INTO tickets(reference,customer_id,customer_company,customer_email,type,category,priority,requirement,status,pending_at,department,portal_token,opened_at,opened_by,opened_by_department) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)')
           ->execute([$ref,(int)$cust['id'],$cust['company'],$from,'Complaint','Other Complaint','Medium',
               ($needsReview?'[NEEDS REVIEW - unmatched sender, link the correct customer] ':'').$subject."\n\n".mb_strimwidth($body,0,4000),
               'Open','Online Technical Support','Online Technical Support',bin2hex(random_bytes(16)),'System (Email)','System']);
        $ticketId=(int)$db->lastInsertId(); $db->commit();
        audit($db,$needsReview?'Email ticket created (Needs Review)':'Email ticket created',$ref,[],$sys);
    }
    $db->prepare('INSERT IGNORE INTO processed_emails(message_id,ticket_id) VALUES(?,?)')->execute([$mid,$ticketId]);
}
imap_close($box);
echo "Mail fetch complete.\n";
