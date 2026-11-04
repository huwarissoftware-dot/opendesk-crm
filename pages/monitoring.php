<?php
declare(strict_types=1);
/* Monitoring webhook: POST with the token from Settings creates a Complaint ticket.
   Actor is audit-logged as System (Monitoring). Accepts JSON or form fields:
   token, customer (company name), category, requirement, priority. */
function page_monitoring(PDO $db): void {
    header('Content-Type: application/json');
    $token=setting($db,'monitoring_token','') ?: '';
    $in=json_decode((string)file_get_contents('php://input'),true) ?: $_POST;
    if ($token==='' || !hash_equals($token,(string)($in['token']??''))) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Invalid token']); return; }
    $company=trim((string)($in['customer']??'')); if ($company==='') { echo json_encode(['ok'=>false,'error'=>'customer required']); return; }
    $q=$db->prepare('SELECT * FROM customers WHERE company=?'); $q->execute([$company]); $cust=$q->fetch();
    if (!$cust) { $db->prepare('INSERT INTO customers(company) VALUES(?)')->execute([$company]); $cust=['id'=>(int)$db->lastInsertId(),'company'=>$company,'email'=>null]; }
    $db->beginTransaction();
    $ref=next_ticket_reference($db,$cust);
    $cat=(string)($in['category']??'Link Down');
    $db->prepare('INSERT INTO tickets(reference,customer_id,customer_company,customer_email,type,category,priority,requirement,status,pending_at,department,portal_token,opened_at,opened_by,opened_by_department) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)')
       ->execute([$ref,(int)$cust['id'],$company,$cust['email']??null,'Complaint',$cat,(string)($in['priority']??'High'),(string)($in['requirement']??'Monitoring alert'),'Open','Online Technical Support','Online Technical Support',bin2hex(random_bytes(16)),'System (Monitoring)','System']);
    $db->commit();
    audit($db,'Monitoring webhook created ticket','tickets',['reference: '.$ref],['name'=>'System (Monitoring)','email'=>'','department'=>'System']);
    echo json_encode(['ok'=>true,'reference'=>$ref]);
}
