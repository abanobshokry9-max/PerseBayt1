<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo 'method not allowed';exit;}
$raw=file_get_contents('php://input')?:'';$header=(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'');$valid=TelegramClient::verifyWebhookHeader($header);$payload=json_decode($raw,true);if(!is_array($payload))$payload=[];$eventId=(string)($payload['update_id']??hash('sha256',$raw));
try{db()->prepare("INSERT IGNORE INTO webhook_events(provider,external_event_id,signature_valid,event_type,payload_json) VALUES ('telegram',?,?,?,?)")->execute([$eventId,$valid?1:0,'telegram_update',j($payload)]);}catch(Throwable){}
if(!$valid){http_response_code(401);echo 'invalid secret';exit;}
try{
    $contact=TelegramClient::inboundContact($payload);
    if($contact){
        try{$agent=AgentService::bySlug('community-manager');$notes='Telegram chat '.($contact['chat_id']?:'unknown').($contact['chat_type']?' · '.$contact['chat_type']:'').($contact['language_code']?' · '.$contact['language_code']:'');$socialContactId=SocialMediaService::upsertContact((int)$agent['id'],'telegram',(string)$contact['external_ref'],(string)$contact['display_name'],'','',(string)$contact['username'],'member',[],pb_substr($notes,0,1000));$message=$payload['message']??$payload['edited_message']??$payload['channel_post']??$payload['edited_channel_post']??null;$message=is_array($message)?$message:[];$messageId=(string)($message['message_id']??'');$text=(string)($message['text']??$message['caption']??'');if($messageId!==''||$text!=='')SocialMediaService::recordInteraction((int)$agent['id'],'telegram','inbound','message',$messageId!==''?'tg:'.$messageId:'',$text,$socialContactId,[]);}catch(Throwable $e){error_log('ELMETR telegram contact sync: '.Security::redactSecrets($e->getMessage(),180));}
    }
    db()->prepare("UPDATE webhook_events SET processing_state='processed' WHERE provider='telegram' AND external_event_id=?")->execute([$eventId]);
}catch(Throwable $e){try{db()->prepare("UPDATE webhook_events SET processing_state='failed',error_code=? WHERE provider='telegram' AND external_event_id=?")->execute([pb_substr(Security::redactSecrets($e->getMessage(),120),0,120),$eventId]);}catch(Throwable){}http_response_code(500);echo 'failed';exit;}
http_response_code(200);echo 'ok';
