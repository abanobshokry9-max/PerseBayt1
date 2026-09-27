<?php
declare(strict_types=1);
final class VoiceAgentCenterService {
    private static function voiceDir():string{
        $dir=PB_ROOT.'/private/runtime/voice';
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('voice_runtime_not_writable');
        if(!is_writable($dir))throw new RuntimeException('voice_runtime_not_writable');
        return $dir;
    }
    public static function createConversation(int $agentId,string $channel='dashboard',string $provider='internal'):int{
        $a=AgentService::assertRunnable(AgentService::byId($agentId));
        AgentPolicyEngine::authorizeAction($agentId,'voice_center','use',['risk'=>'low','external'=>false,'owner_approved'=>true]);
        $uid=uid('VC');
        $q=db()->prepare("INSERT INTO voice_conversations(uid,agent_id,channel_key,provider_key,state,started_at,updated_at) VALUES (?,?,?,?,'active',NOW(),NOW())");
        $q->execute([$uid,$agentId,pb_substr($channel,0,50),pb_substr($provider,0,80)]);
        $id=(int)db()->lastInsertId();
        Audit::log('owner',(string)(Auth::user()['id']??1),'voice.conversation_create','voice_conversation',(string)$id,'verified',null,null,['agent_slug'=>$a['slug'],'channel'=>$channel]);
        return $id;
    }
    public static function get(int $id):array{
        $q=db()->prepare('SELECT v.*,a.slug agent_slug,a.display_name agent_name FROM voice_conversations v JOIN agents a ON a.id=v.agent_id WHERE v.id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('voice_conversation_not_found');return $r;
    }
    public static function recent(int $limit=80):array{$limit=max(1,min(200,$limit));return db()->query('SELECT v.*,a.slug agent_slug,a.display_name agent_name,(SELECT COUNT(*) FROM voice_turns t WHERE t.conversation_id=v.id) turn_count FROM voice_conversations v JOIN agents a ON a.id=v.agent_id ORDER BY v.id DESC LIMIT '.$limit)->fetchAll();}
    public static function turns(int $id):array{$q=db()->prepare('SELECT * FROM voice_turns WHERE conversation_id=? ORDER BY id');$q->execute([$id]);return $q->fetchAll();}
    public static function storeUpload(array $file):string{
        $err=(int)($file['error']??UPLOAD_ERR_NO_FILE);if($err!==UPLOAD_ERR_OK)throw new RuntimeException('voice_upload_failed:'.$err);
        $tmp=(string)($file['tmp_name']??'');if(!is_file($tmp))throw new RuntimeException('voice_upload_missing');
        $size=(int)($file['size']??filesize($tmp));if($size<1||$size>25*1024*1024)throw new RuntimeException('voice_upload_size_invalid');
        $mime=mime_content_type($tmp)?:'application/octet-stream';$allowed=['audio/mpeg'=>'mp3','audio/mp3'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/webm'=>'webm','audio/ogg'=>'ogg','audio/mp4'=>'m4a','video/webm'=>'webm'];
        if(!isset($allowed[$mime]))throw new RuntimeException('voice_upload_type_invalid');
        $name='input-'.gmdate('Ymd-His').'-'.substr(hash('sha256',$tmp.microtime(true).random_bytes(8)),0,12).'.'.$allowed[$mime];$dest=self::voiceDir().'/'.$name;
        $ok=is_uploaded_file($tmp)?move_uploaded_file($tmp,$dest):copy($tmp,$dest);if(!$ok)throw new RuntimeException('voice_upload_store_failed');@chmod($dest,0600);return $dest;
    }
    public static function processAudio(int $agentId,string $path,?int $conversationId=null):array{
        $a=AgentService::assertRunnable(AgentService::byId($agentId));if($conversationId===null)$conversationId=self::createConversation($agentId,'dashboard','internal');$c=self::get($conversationId);if((int)$c['agent_id']!==$agentId)throw new RuntimeException('voice_conversation_agent_mismatch');
        AgentPolicyEngine::authorizeAction($agentId,'voice_center','use',['risk'=>'low','external'=>true,'owner_approved'=>true,'gateway_managed'=>true]);
        $stt=ProviderCapabilityRouter::transcribe($agentId,$path);$text=trim((string)($stt['text']??''));if($text==='')throw new RuntimeException('voice_transcript_empty');
        $session=ConversationService::agentOwnerSession((string)$a['slug']);$out=DirectAgentChat::handle((string)$a['slug'],(int)$session['id'],$text);$reply=trim((string)($out['reply']??''));if($reply==='')$reply='I received the request but no spoken reply was generated.';
        $tts=null;try{$tts=ProviderCapabilityRouter::speak($agentId,$reply);}catch(Throwable $e){$tts=['error'=>Security::redactSecrets($e->getMessage(),300)];}
        $q=db()->prepare("INSERT INTO voice_turns(conversation_id,turn_no,direction,input_text,output_text,input_audio_path,output_audio_path,stt_provider,tts_provider,metadata_json,created_at) VALUES (?,COALESCE((SELECT MAX(x.turn_no)+1 FROM voice_turns x WHERE x.conversation_id=?),1),'owner_to_agent',?,?,?,?,?,?,?,NOW())");
        $q->execute([$conversationId,$conversationId,$text,$reply,self::relativeVoicePath($path),isset($tts['path'])?self::relativeVoicePath((string)$tts['path']):null,(string)($stt['provider']??''),(string)($tts['provider']??''),j(['stt_model'=>$stt['model']??null,'tts_model'=>$tts['model']??null,'tts_error'=>$tts['error']??null])]);
        db()->prepare("UPDATE voice_conversations SET provider_key=?,state='active',last_turn_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(string)($stt['provider']??'internal'),$conversationId]);
        return ['conversation_id'=>$conversationId,'agent'=>$a,'transcript'=>$text,'reply'=>$reply,'input_audio'=>self::relativeVoicePath($path),'output_audio'=>$tts['path']??null,'stt'=>$stt,'tts'=>$tts];
    }
    public static function processText(int $agentId,string $text,?int $conversationId=null,bool $speak=true):array{
        $text=trim($text);if($text==='')throw new RuntimeException('voice_text_required');$a=AgentService::assertRunnable(AgentService::byId($agentId));if($conversationId===null)$conversationId=self::createConversation($agentId,'dashboard','internal');
        $session=ConversationService::agentOwnerSession((string)$a['slug']);$out=DirectAgentChat::handle((string)$a['slug'],(int)$session['id'],$text);$reply=trim((string)($out['reply']??''));if($reply==='')$reply='No reply was generated.';$tts=null;if($speak){try{$tts=ProviderCapabilityRouter::speak($agentId,$reply);}catch(Throwable $e){$tts=['error'=>Security::redactSecrets($e->getMessage(),300)];}}
        $q=db()->prepare("INSERT INTO voice_turns(conversation_id,turn_no,direction,input_text,output_text,input_audio_path,output_audio_path,stt_provider,tts_provider,metadata_json,created_at) VALUES (?,COALESCE((SELECT MAX(x.turn_no)+1 FROM voice_turns x WHERE x.conversation_id=?),1),'owner_to_agent',?,?,NULL,?,NULL,?,?,NOW())");
        $q->execute([$conversationId,$conversationId,$text,$reply,isset($tts['path'])?self::relativeVoicePath((string)$tts['path']):null,(string)($tts['provider']??''),j(['tts_model'=>$tts['model']??null,'tts_error'=>$tts['error']??null])]);
        db()->prepare("UPDATE voice_conversations SET state='active',last_turn_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$conversationId]);
        return ['conversation_id'=>$conversationId,'agent'=>$a,'transcript'=>$text,'reply'=>$reply,'output_audio'=>$tts['path']??null,'tts'=>$tts];
    }
    public static function startOwnerCall(int $agentId,string $provider='twilio'):array{
        $a=AgentService::assertRunnable(AgentService::byId($agentId));if(class_exists('AgentUsageBudgetService'))AgentUsageBudgetService::authorize($agentId,'voice');AgentPolicyEngine::authorizeAction($agentId,'voice_center','use',['risk'=>'high','external'=>true,'owner_approved'=>true,'gateway_managed'=>true]);$to=CommunicationGateway::ownerPhone();if($to==='')throw new RuntimeException('owner_phone_missing');$cid=self::createConversation($agentId,'voice',$provider);$base=rtrim((string)config('app.base_url'),' /');
        if($provider==='whatsapp'){$r=WhatsAppCallingService::outboundOwner($agentId);$external=(string)($r['call_id']??'');}
        elseif($provider==='generic'){$r=CallBridgeClient::call($to,$a);$external=(string)($r['call_id']??$r['id']??$r['session_id']??'');}
        elseif($provider==='twilio'){$voice=$base.'/webhooks/twilio-voice.php?agent='.rawurlencode((string)$a['slug']).'&vc='.$cid;$status=$base.'/webhooks/twilio-status.php?agent='.rawurlencode((string)$a['slug']).'&vc='.$cid;$r=TwilioClient::call($to,$voice,$status);$external=(string)($r['sid']??'');}
        else throw new RuntimeException('voice_provider_unsupported');
        db()->prepare("UPDATE voice_conversations SET channel_key='voice',provider_key=?,external_call_id=?,state='ringing',updated_at=NOW() WHERE id=?")->execute([$provider,$external?:null,$cid]);
        Audit::log('owner',(string)(Auth::user()['id']??1),'voice.call_start','voice_conversation',(string)$cid,'attempted',null,null,['agent_slug'=>$a['slug'],'provider'=>$provider,'external_call_id'=>$external]);if(class_exists('AgentUsageBudgetService'))AgentUsageBudgetService::record($agentId,'voice',1,0,['provider'=>$provider,'kind'=>'call']);return ['conversation_id'=>$cid,'provider'=>$provider,'agent'=>$a,'external_call_id'=>$external,'result'=>$r];
    }
    public static function updateCallState(int $conversationId,string $state,string $externalId=''):void{$allowed=['requested','ringing','connected','completed','failed','blocked'];if(!in_array($state,$allowed,true))$state='requested';db()->prepare("UPDATE voice_conversations SET state=?,external_call_id=COALESCE(NULLIF(?,''),external_call_id),ended_at=IF(? IN ('completed','failed','blocked'),NOW(),ended_at),updated_at=NOW() WHERE id=?")->execute([$state,$externalId,$state,$conversationId]);}
    public static function audioPath(int $turnId,string $kind='output'):string{$col=$kind==='input'?'input_audio_path':'output_audio_path';$q=db()->prepare('SELECT '.$col.' FROM voice_turns WHERE id=?');$q->execute([$turnId]);$rel=(string)($q->fetchColumn()?:'');if($rel==='')throw new RuntimeException('voice_audio_missing');$path=PB_ROOT.'/private/runtime/voice/'.basename($rel);$real=realpath($path);$root=realpath(self::voiceDir());if(!$real||!$root||!str_starts_with($real,$root.DIRECTORY_SEPARATOR)||!is_file($real))throw new RuntimeException('voice_audio_missing');return $real;}
    private static function relativeVoicePath(string $path):string{return basename($path);}
}
