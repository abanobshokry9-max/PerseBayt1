<?php
declare(strict_types=1);
final class WhatsAppCallingService {
    public static function routeAgent(array $payload,string $from='',string $to=''):array{
        $requested=trim((string)($payload['agent_slug']??$payload['agent']['slug']??$payload['route']['agent_slug']??''));
        $requestedId=(int)($payload['agent_id']??$payload['agent']['id']??$payload['route']['agent_id']??0);
        $agent=null;
        try{
            if($requested!=='')$agent=AgentService::bySlug($requested);
            elseif($requestedId>0)$agent=AgentService::byId($requestedId);
        }catch(Throwable){$agent=null;}
        $owner=CommunicationGateway::isOwnerNumber($from)||CommunicationGateway::isOwnerNumber($to);
        if(!$agent&&$owner){
            $fallback=trim((string)setting('voice.owner_default_agent','ramy'))?:'ramy';
            try{$agent=AgentService::bySlug($fallback);}catch(Throwable){$agent=null;}
        }
        if(!$agent){try{$agent=AgentService::bySlug('ramy');}catch(Throwable){throw new RuntimeException('voice_agent_missing');}}
        $agent=AgentService::assertRunnable($agent);
        if($owner&&!Permissions::agent((int)$agent['id'],'whatsapp.call.receive')){
            try{$ramy=AgentService::assertRunnable(AgentService::bySlug('ramy'));if(Permissions::agent((int)$ramy['id'],'whatsapp.call.receive'))$agent=$ramy;}catch(Throwable){}
        }
        return $agent;
    }
    public static function handleMetaEvent(array $call):array{
        $callId=trim((string)($call['id']??$call['call_id']??''));if($callId==='')$callId='wa_'.substr(hash('sha256',j($call)),0,28);
        $from=ConversationService::normalizePhone((string)($call['from']??$call['wa_id']??''));$to=ConversationService::normalizePhone((string)($call['to']??CommunicationGateway::ownerPhone()));
        $event=pb_strtolower((string)($call['event']??$call['status']??'offered'));$state=match(true){str_contains($event,'connect')=>'connected',str_contains($event,'ring')=>'ringing',str_contains($event,'complete')||str_contains($event,'terminate')=>'completed',str_contains($event,'reject')=>'rejected',str_contains($event,'fail')=>'failed',default=>'offered'};
        $agent=self::routeAgent($call,$from,$to);$agentId=(int)$agent['id'];
        db()->prepare("INSERT INTO whatsapp_call_sessions(provider_call_id,direction,from_ref,to_ref,agent_id,state,raw_json,started_at,ended_at) VALUES (?,'inbound',?,?,?,?,?,IF(? IN ('connected','ringing'),NOW(),NULL),IF(? IN ('completed','rejected','failed'),NOW(),NULL)) ON DUPLICATE KEY UPDATE agent_id=VALUES(agent_id),state=VALUES(state),raw_json=VALUES(raw_json),started_at=COALESCE(started_at,VALUES(started_at)),ended_at=COALESCE(VALUES(ended_at),ended_at),updated_at=NOW()")
            ->execute([$callId,$from?:null,$to?:null,$agentId,$state,j($call),$state,$state]);
        $bridge=null;if(setting('whatsapp.calling.enabled','0')==='1'&&CallBridgeClient::configured()){
            try{$bridge=CallBridgeClient::whatsappEvent($call,$agent);$bridgeId=(string)($bridge['id']??$bridge['session_id']??$bridge['call_id']??'');db()->prepare("UPDATE whatsapp_call_sessions SET bridge_session_id=?,signaling_json=?,state=IF(state='offered','ringing',state) WHERE provider_call_id=?")->execute([$bridgeId?:null,j($bridge),$callId]);}
            catch(Throwable $e){db()->prepare("UPDATE whatsapp_call_sessions SET state='blocked',signaling_json=? WHERE provider_call_id=?")->execute([j(['error'=>pb_substr(Security::redactSecrets($e->getMessage()),0,300)]),$callId]);}
        }
        try{Notifications::add($bridge?'info':'warning','voice',$bridge?'WhatsApp call routed to '.(string)$agent['display_name']:'WhatsApp call needs calling bridge',$bridge?'The inbound call event was sent to the configured voice bridge for the selected agent.':'The call event was recorded, but no compatible WhatsApp calling bridge is active.','call',$callId);}catch(Throwable){}
        return ['call_id'=>$callId,'state'=>$bridge?'ringing':$state,'agent_slug'=>(string)$agent['slug'],'bridge'=>$bridge];
    }
    public static function outboundOwner(int $agentId):array{
        $agent=AgentService::assertRunnable(AgentService::byId($agentId));
        Permissions::requireAgent($agentId,'whatsapp.send');Permissions::requireAgent($agentId,'whatsapp.call.initiate');
        $to=CommunicationGateway::ownerPhone();if($to==='')throw new RuntimeException('owner_phone_missing');
        if(setting('whatsapp.calling.enabled','0')!=='1')throw new RuntimeException('whatsapp_calling_not_enabled');
        if(setting('whatsapp.calling.outbound_allowed','0')!=='1')throw new RuntimeException('whatsapp_outbound_call_not_allowed_for_sender');
        if(!CallBridgeClient::configured())throw new RuntimeException('call_bridge_not_configured');
        $r=CallBridgeClient::whatsappOutbound($to,$agent);$id=(string)($r['id']??$r['call_id']??$r['session_id']??('out_'.substr(hash('sha256',j($r).microtime(true)),0,24)));
        db()->prepare("INSERT INTO whatsapp_call_sessions(provider_call_id,direction,from_ref,to_ref,agent_id,state,bridge_session_id,signaling_json,started_at) VALUES (?,'outbound',NULL,?,?,'ringing',?,?,NOW())")
            ->execute([$id,$to,$agentId,(string)($r['session_id']??$id),j($r)]);
        return ['call_id'=>$id,'state'=>'ringing','agent_slug'=>(string)$agent['slug'],'bridge'=>$r];
    }
    public static function agentForCall(string $callId):?array{
        if($callId==='')return null;$q=db()->prepare("SELECT a.* FROM whatsapp_call_sessions w JOIN agents a ON a.id=w.agent_id WHERE w.provider_call_id=? OR w.bridge_session_id=? ORDER BY w.id DESC LIMIT 1");$q->execute([$callId,$callId]);$a=$q->fetch();return $a?:null;
    }
    public static function recent(int $limit=80):array{$limit=max(1,min(200,$limit));return db()->query('SELECT w.*,a.display_name agent_name,a.slug agent_slug FROM whatsapp_call_sessions w LEFT JOIN agents a ON a.id=w.agent_id ORDER BY w.id DESC LIMIT '.$limit)->fetchAll();}
}
