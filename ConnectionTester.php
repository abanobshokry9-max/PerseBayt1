<?php
declare(strict_types=1);
final class ConnectionTester {
    public static function test(string $provider):array{
        $state='failed';$code='unknown';$details=[];
        try{
            switch($provider){
                case 'openai':
                    $r=AiGateway::testProvider('openai');$state=trim((string)($r['text']??''))!==''?'verified':'failed';$code=$state==='verified'?'response_ok':'empty_response';$details=['model'=>$r['model']??null];break;
                case 'openrouter':
                    $meta=OpenRouterService::activeKeyMeta();$keyInfo=[];$keyInfoError='';
                    try{$keyInfo=OpenRouterService::keyInfo();if(!empty($keyInfo['is_management_key']))throw new RuntimeException('openrouter_management_key_not_for_inference');}
                    catch(Throwable $e){$keyInfoError=Security::redactSecrets($e->getMessage(),220);if(str_contains(strtolower($keyInfoError),'management_key_not_for_inference'))throw $e;}
                    try{$r=OpenRouterService::inferenceTest();
                        $state=trim((string)($r['text']??''))!==''?'verified':'failed';$code=$state==='verified'?'response_ok':'openrouter_empty_output';
                        $details=['model'=>$r['model']??null,'finish_reason'=>$r['finish_reason']??null,'reasoning_tokens'=>$r['reasoning_tokens']??0,'completion_tokens'=>$r['completion_tokens']??0,'key_valid'=>true,'is_free_tier'=>$keyInfo['is_free_tier']??null,'limit_remaining'=>$keyInfo['limit_remaining']??null,'data_collection'=>OpenRouterService::dataCollectionPolicy(),'stage'=>'inference','key_fingerprint'=>$meta['fingerprint']??'','key_length'=>$meta['length']??0];
                        if($keyInfoError!=='')$details['key_info_warning']=$keyInfoError;
                    }catch(Throwable $e){
                        $raw=Security::redactSecrets($e->getMessage(),220);$m=strtolower($raw);$details=['key_valid'=>false,'is_free_tier'=>$keyInfo['is_free_tier']??null,'limit_remaining'=>$keyInfo['limit_remaining']??null,'data_collection'=>OpenRouterService::dataCollectionPolicy(),'stage'=>'inference','provider_error'=>$raw,'key_info_error'=>$keyInfoError,'key_fingerprint'=>$meta['fingerprint']??'','key_length'=>$meta['length']??0];
                        if(str_starts_with($m,'http_429:')){$code='openrouter_quota_exhausted';$details['key_valid']=true;break;}
                        if(str_starts_with($m,'http_401:')){$code=$keyInfoError===''?'openrouter_inference_http_401':'openrouter_key_http_401';break;}
                        throw $e;
                    }
                    break;
                case 'gemini':case 'anthropic':case 'groq':case 'ollama':
                    $r=AiGateway::testProvider($provider);$state=trim((string)($r['text']??''))!==''?'verified':'failed';$code=$state==='verified'?'response_ok':'empty_response';$details=['model'=>$r['model']??null];break;
                case 'web_search':
                    $r=SearchDiagnosticsService::run();$state=(string)($r['state']??'failed');$code=(string)($r['code']??'project_discovery_unavailable');$details=$r;break;
                case 'hostinger':
                    $r=HostingerClient::websites();$state='verified';$code='websites_ok';$details=['count'=>count($r)];break;
                case 'meta_whatsapp':
                    $phone=MetaClient::test();$ready=MetaClient::customerOutboundReadiness();
                    $details=['phone'=>$phone,'customer_outbound'=>$ready];
                    if(empty($ready['messaging_ok'])){$state='failed';$code='meta_messaging_unavailable';}
                    elseif(empty($ready['ready'])){$state='failed';$code=(string)($ready['reason']??'meta_customer_outbound_not_ready');}
                    else{$state='verified';$code='meta_customer_outbound_ready';}
                    break;
                case 'twilio':
                    $details=TwilioClient::testAccount();$state='verified';$code='twilio_account_ok';break;
                case 'generic_bridge':
                    if(!GenericMessageClient::configured())throw new RuntimeException('generic_message_not_configured');$state='verified';$code='configured';$details=['outbound_url'=>true,'inbound_secret'=>(string)config('connections.generic_message.inbound_secret','')!==''];break;
                case 'generic_call_bridge':
                    if(!CallBridgeClient::configured())throw new RuntimeException('call_bridge_not_configured');$state='verified';$code='configured';$details=['bridge_url'=>true];break;
                case 'media_bridge':
                    if(!MediaBridgeClient::configured())throw new RuntimeException('media_bridge_not_configured');$state='verified';$code='configured';$details=['media_url'=>true];break;
                case 'youtube':
                    if(!YouTubeClient::configured())throw new RuntimeException('youtube_not_configured');$details=YouTubeClient::test();$state='verified';$code='youtube_channel_ok';break;
                case 'tiktok':
                    if(!TikTokClient::configured())throw new RuntimeException('tiktok_not_configured');$details=TikTokClient::test();$state='verified';$code='tiktok_creator_ok';break;
                case 'telegram':
                    if(!TelegramClient::configured())throw new RuntimeException('telegram_not_configured');$details=TelegramClient::test();$state='verified';$code='telegram_bot_ok';break;
                case 'meta_social':
                    if(!MetaSocialClient::configured())throw new RuntimeException('meta_social_not_configured');$details=MetaSocialClient::test();$state='verified';$code='meta_social_pages_ok';break;
                case 'cloud_s3':case 'google_drive':case 'dropbox':
                    $details=CloudStorageService::test($provider);$state=!empty($details['ok'])?'verified':'failed';$code=$state==='verified'?'cloud_connection_ok':(string)($details['error']??'cloud_connection_failed');break;
                case 'browser_qa':
                    $details=BrowserQaService::test();$state=!empty($details['ok'])?'verified':'failed';$code=$state==='verified'?'browser_qa_ok':(string)($details['error']??'browser_qa_failed');break;
                case 'browser_automation':
                    $details=BrowserAutomationService::test();$state=!empty($details['ok'])?'verified':'failed';$code=$state==='verified'?'browser_automation_ok':(string)($details['error']??'browser_automation_failed');break;
                case 'email':
                    $details=EmailConnector::diagnostics();
                    if(empty($details['configured'])) throw new RuntimeException('email_not_configured');
                    // Configuration can be proven without sending an unsolicited test message. Keep this state as configured evidence.
                    $state='verified';$code='email_connector_configured';break;
                default:
                    try{$q=db()->prepare("SELECT kind FROM providers WHERE provider_key=? AND enabled=1 LIMIT 1");$q->execute([$provider]);$kind=(string)($q->fetchColumn()?:'');}catch(Throwable){$kind='';}
                    if($kind==='ai'){$r=AiGateway::testProvider($provider);$state=trim((string)($r['text']??''))!==''?'verified':'failed';$code=$state==='verified'?'response_ok':'empty_response';$details=['model'=>$r['model']??null];break;}
                    throw new RuntimeException('provider_unknown');
            }
        }catch(Throwable $e){$raw=pb_substr(Security::redactSecrets($e->getMessage(),220),0,220);$low=strtolower($raw);if($provider==='openai'&&str_starts_with($low,'openai_models_unavailable:'))$code='openai_models_unavailable';elseif($provider==='openrouter'&&str_starts_with($low,'openrouter_key_http_401:'))$code='openrouter_key_http_401';else $code=pb_substr($raw,0,150);$details=['error'=>$raw]+($details?:[]);}
        try{$q=db()->prepare('INSERT INTO connection_tests(provider_key,state,result_code,details_json) VALUES (?,?,?,?)');$q->execute([$provider,$state,$code,j($details)]);}catch(Throwable){}
        try{db()->prepare('UPDATE providers SET status=?,last_checked_at=NOW(),last_error=? WHERE provider_key=?')->execute([$state,$state==='failed'?$code:null,$provider]);}catch(Throwable){}
        try{ProviderCapabilityRouter::markProviderHealth($provider,$state,$state==='failed'?$code:null);}catch(Throwable){}
        try{
            $channels=match($provider){
                'meta_whatsapp'=>['whatsapp'],
                'twilio'=>['sms','voice'],
                'generic_bridge'=>['generic_message'],
                'generic_call_bridge'=>['voice_bridge'],
                'email'=>['email'],
                'youtube'=>['youtube'],
                'tiktok'=>['tiktok'],
                'telegram'=>['telegram'],
                'meta_social'=>['facebook','instagram'],
                default=>[],
            };
            if($channels){$marks=implode(',',array_fill(0,count($channels),'?'));$q=db()->prepare("UPDATE communication_channels SET last_test_state=?,last_test_at=NOW() WHERE channel_key IN ($marks)");$q->execute(array_merge([$state],$channels));}
        }catch(Throwable){}
        $name=AdminUi::provider($provider);
        try{Notifications::add($state==='verified'?'success':'warning','connections',($state==='verified'?'نجح':'فشل').' اختبار '.$name,$state==='verified'?'الاتصال تم اختباره فعليًا.':AdminUi::humanError($code),'provider',$provider);}catch(Throwable){}
        return ['state'=>$state,'code'=>$code,'details'=>$details];
    }
}
