<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$raw=(string)file_get_contents('php://input');
$data=json_decode($raw,true);if(!is_array($data))$data=$_POST;
$provided=(string)($_SERVER['HTTP_X_ELMETR_SECRET']??$_SERVER['HTTP_X_BRIDGE_SECRET']??'');
$expected=(string)config('calls.bridge_secret','');
$valid=$expected!==''&&$provided!==''&&hash_equals($expected,$provided);
$callId=(string)($data['call_id']??$data['id']??$data['provider_call_id']??'');
$event=(string)($data['event']??$data['type']??'call.event');
try{
    db()->prepare("INSERT INTO webhook_events(provider,external_event_id,signature_valid,event_type,processing_state,payload_json) VALUES ('generic_call_bridge',?,?,?,'received',?) ON DUPLICATE KEY UPDATE signature_valid=VALUES(signature_valid),event_type=VALUES(event_type),payload_json=VALUES(payload_json)")->execute([$callId?:hash('sha256',$raw),$valid?1:0,$event,j($data)]);
    if(!$valid){http_response_code(403);echo j(['ok'=>false,'error'=>'signature_invalid']);exit;}
    $channelId=db()->query("SELECT id FROM communication_channels WHERE channel_key='voice_bridge' LIMIT 1")->fetchColumn()?:null;
    $from=(string)($data['from']??'');$to=(string)($data['to']??'');
    if(in_array($event,['call.incoming','call.started','call.connected','call.status'],true)){
        $state=(string)($data['state']??$data['status']??($event==='call.connected'?'connected':'requested'));
        $allowed=['requested','queued','ringing','connected','completed','failed','rejected','unsupported'];if(!in_array($state,$allowed,true))$state='requested';
        $direction=(string)($data['direction']??'inbound');if(!in_array($direction,['inbound','outbound'],true))$direction='inbound';
        $agentSlug=trim((string)($data['agent_slug']??$data['agent']['slug']??''));if($agentSlug===''){try{$ca=WhatsAppCallingService::agentForCall($callId);if($ca)$agentSlug=(string)$ca['slug'];}catch(Throwable){}}$purpose=(CommunicationGateway::isOwnerNumber($from)||CommunicationGateway::isOwnerNumber($to))?'مكالمة المالك مع '.($agentSlug!==''?$agentSlug:'الوكالة'):'مكالمة عميل مع الوكالة';
        db()->prepare("INSERT INTO calls(channel_id,direction,from_ref,to_ref,purpose,provider_call_id,state,raw_json,started_at,ended_at) VALUES (?,?,?,?,?,?,?, ?,IF(? IN ('connected','completed'),NOW(),NULL),IF(? IN ('completed','failed','rejected'),NOW(),NULL)) ON DUPLICATE KEY UPDATE state=VALUES(state),raw_json=VALUES(raw_json),started_at=COALESCE(started_at,VALUES(started_at)),ended_at=COALESCE(VALUES(ended_at),ended_at)")->execute([$channelId,$direction,$from?:null,$to?:null,$purpose,$callId?:null,$state,j($data),$state,$state]);
    }
    $speech=trim((string)($data['speech']??$data['text']??$data['transcript']??''));
    $reply='';
    if($speech!==''){$out=CommunicationGateway::voiceInput($from,$speech,$callId?:uid('CALL'),$to,'voice_bridge','generic_call_bridge',$data,$agentSlug??'');$reply=(string)$out['reply'];}
    db()->prepare("UPDATE webhook_events SET processing_state='processed' WHERE provider='generic_call_bridge' AND external_event_id=?")->execute([$callId?:hash('sha256',$raw)]);
    echo j(['ok'=>true,'reply'=>$reply,'language'=>'ar-EG']);
}catch(Throwable $e){
    try{db()->prepare("UPDATE webhook_events SET processing_state='failed',error_code=? WHERE provider='generic_call_bridge' AND external_event_id=?")->execute([pb_substr($e->getMessage(),0,120),$callId?:hash('sha256',$raw)]);}catch(Throwable){}
    try{Notifications::add('critical','voice','فشل استقبال بيانات المكالمات الخارجية',AdminUi::humanError($e->getMessage()),'call',$callId?:null);}catch(Throwable){}
    http_response_code(400);echo j(['ok'=>false,'error'=>'processing_failed']);
}
