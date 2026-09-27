<?php
declare(strict_types=1);
require_once __DIR__.'/src/bootstrap.php';
Auth::requireOwner();
header('Content-Type: application/json; charset=UTF-8');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo j(['ok'=>false,'error'=>'method']);exit;}
Auth::verifyCsrf();
$text=trim((string)($_POST['message']??''));
if($text===''){http_response_code(422);echo j(['ok'=>false,'error'=>'message_required']);exit;}
try{
    $out=TeamChatService::ownerCommand($text);
    echo j(['ok'=>true,'reply'=>(string)($out['reply']??''),'action'=>$out['action']??null,'task_id'=>$out['task_id']??($out['execution']['primary_task']??null),'sender_slug'=>$out['sender_slug']??'ramy','sender_name'=>$out['sender_name']??'رامي']);
}catch(Throwable $e){
    http_response_code(500);
    echo j(['ok'=>false,'error'=>AdminUi::humanError(Security::redactSecrets($e->getMessage(),180))]);
}
