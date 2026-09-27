<?php
declare(strict_types=1);
require_once __DIR__.'/src/bootstrap.php';
Auth::requireOwner();
header('Content-Type: application/json; charset=UTF-8');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo j(['ok'=>false]);exit;}
Auth::verifyCsrf();
try{
    $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['ids']??[])),static fn($v)=>$v>0)));
    $ids=array_slice($ids,0,100);
    if(!$ids){echo j(['ok'=>true,'updated'=>0,'unread'=>(int)db()->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn()]);exit;}
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $q=db()->prepare("UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE read_at IS NULL AND id IN ($ph)");$q->execute($ids);
    echo j(['ok'=>true,'updated'=>$q->rowCount(),'unread'=>(int)db()->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn()]);
}catch(Throwable){http_response_code(500);echo j(['ok'=>false]);}
