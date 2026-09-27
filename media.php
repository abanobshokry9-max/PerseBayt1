<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$raw=(string)file_get_contents('php://input');$d=json_decode($raw,true);if(!is_array($d))$d=$_POST;
$provided=(string)($_SERVER['HTTP_X_ELMETR_SECRET']??$_SERVER['HTTP_X_BRIDGE_SECRET']??'');$expected=(string)config('connections.media.inbound_secret','');
if($expected===''||$provided===''||!hash_equals($expected,$provided)){http_response_code(403);echo j(['ok'=>false]);exit;}
$id=(int)($d['request_id']??0);if($id<1){http_response_code(422);echo j(['ok'=>false,'error'=>'request_id_required']);exit;}
try{$out=MediaBridgeClient::applyProviderResponse($id,$d,true);echo j(['ok'=>true,'result'=>$out]);}
catch(Throwable $e){http_response_code(400);error_log('ELMETR media webhook: '.Security::redactSecrets($e->getMessage(),220));echo j(['ok'=>false,'error'=>'processing_failed']);}
