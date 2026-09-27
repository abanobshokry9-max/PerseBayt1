<?php
declare(strict_types=1);
require_once __DIR__.'/src/bootstrap.php';
Auth::requireOwner();header('Content-Type: application/json; charset=UTF-8');if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo j(['ok'=>false]);exit;}Auth::verifyCsrf();$text=trim((string)($_POST['message']??''));if($text===''){http_response_code(422);echo j(['ok'=>false,'error'=>'empty']);exit;}try{$r=CommunicationGateway::dashboard($text);echo j(['ok'=>true]+$r);}catch(Throwable $e){http_response_code(500);echo j(['ok'=>false,'error'=>'execution_failed']);}
