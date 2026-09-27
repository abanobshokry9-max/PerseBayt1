<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$raw=(string)file_get_contents('php://input');$data=json_decode($raw,true);if(!is_array($data)){$data=$_POST;}
$secret=(string)($_SERVER['HTTP_X_ELMETR_SECRET']??$_SERVER['HTTP_X_BRIDGE_SECRET']??'');
$valid=GenericMessageClient::verifyInbound($secret);$eid=trim((string)($data['id']??$data['event_id']??''));if($eid==='')$eid=hash('sha256',$raw);
try{
    db()->prepare("INSERT INTO webhook_events(provider,external_event_id,signature_valid,event_type,processing_state,payload_json) VALUES ('generic_bridge',?,?,?,'received',?) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json)")->execute([$eid,$valid?1:0,(string)($data['event']??'message'),j($data)]);
    if(!$valid){http_response_code(403);echo j(['ok'=>false,'error'=>'signature_invalid']);exit;}
    $from=(string)($data['from']??'');$text=trim((string)($data['text']??$data['body']??''));if($from===''||$text==='')throw new RuntimeException('generic_message_fields_missing');
    $out=CommunicationGateway::inbound('generic_message','generic_bridge',$from,$text,$eid,$data);
    db()->prepare("UPDATE webhook_events SET processing_state='processed' WHERE provider='generic_bridge' AND external_event_id=?")->execute([$eid]);echo j(['ok'=>true,'action'=>$out['action']??null]);
}catch(Throwable $e){try{db()->prepare("UPDATE webhook_events SET processing_state='failed',error_code=? WHERE provider='generic_bridge' AND external_event_id=?")->execute([pb_substr($e->getMessage(),0,120),$eid]);}catch(Throwable){}http_response_code(400);echo j(['ok'=>false,'error'=>'processing_failed']);}
