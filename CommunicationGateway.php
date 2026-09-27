<?php
declare(strict_types=1);
final class CommunicationGateway {
    public static function ownerPhone():string{return preg_replace('/\D+/','',(string)setting('owner.phone',''));}
    private static function digits(string $n):string{$n=preg_replace('/\D+/','',$n);return str_starts_with($n,'00')?substr($n,2):$n;}
    private static function e164(string $n):string{$d=self::digits($n);return $d===''?'':'+'.$d;}
    public static function isOwnerNumber(string $from):bool{$a=self::digits($from);$b=self::digits(self::ownerPhone());return $a!==''&&$b!==''&&hash_equals($b,$a);}
    private static function duplicate(string $provider,string $externalId):?int{if($externalId==='')return null;$q=db()->prepare('SELECT id FROM messages WHERE provider=? AND external_id=? LIMIT 1');$q->execute([$provider,$externalId]);$id=$q->fetchColumn();return $id?(int)$id:null;}
    private static function activeRoute(string $channel):?array{try{$q=db()->prepare("SELECT r.*,a.slug,a.display_name,a.owner_communication,a.whatsapp_enabled FROM owner_channel_routes r JOIN agents a ON a.id=r.agent_id JOIN agent_channel_permissions cp ON cp.agent_id=a.id AND cp.channel_key=r.channel_key WHERE r.channel_key=? AND r.state='active' AND r.expires_at>NOW() AND a.is_active=1 AND a.status<>'disabled' AND cp.can_receive_owner=1 AND (a.slug='ramy' OR (r.channel_key='dashboard' AND a.owner_communication IN ('dashboard_only','dashboard_whatsapp')) OR (r.channel_key='whatsapp' AND a.owner_communication IN ('whatsapp','dashboard_whatsapp','emergency_only') AND a.whatsapp_enabled=1)) LIMIT 1");$q->execute([$channel]);return $q->fetch()?:null;}catch(Throwable){return null;}}
    private static function activateRoute(string $channel,int $agentId,int $sessionId):void{db()->prepare("INSERT INTO owner_channel_routes(channel_key,agent_id,session_id,state,expires_at) VALUES (?,?,?,'active',DATE_ADD(NOW(),INTERVAL 2 HOUR)) ON DUPLICATE KEY UPDATE agent_id=VALUES(agent_id),session_id=VALUES(session_id),state='active',expires_at=VALUES(expires_at),updated_at=NOW()")->execute([$channel,$agentId,$sessionId]);}
    private static function closeRoute(string $channel):void{try{db()->prepare("UPDATE owner_channel_routes SET state='closed',updated_at=NOW() WHERE channel_key=?")->execute([$channel]);}catch(Throwable){}}
    private static function explicitRamy(string $text):bool{return (bool)preg_match('/(^|\s)(رامي|ramy)(\s|$)/iu',trim($text));}
    public static function inbound(string $channel,string $provider,string $from,string $text,string $externalId='',array $raw=[]):array{
        if(($dup=self::duplicate($provider,$externalId))!==null)return ['duplicate'=>true,'message_id'=>$dup,'reply'=>''];
        $owner=self::isOwnerNumber($from);Audit::setActor($owner?'owner':'customer',$owner?'1':self::digits($from));
        if($owner&&!self::explicitRamy($text)&&($route=self::activeRoute($channel))){
            $session=ConversationService::session((int)$route['session_id']);$conv=self::conversationForSession((int)$session['id'],$channel,$provider);
            $mid=ConversationService::message((int)$conv['id'],$channel,$provider,'inbound','owner','1','agent',(string)$route['slug'],$text,$externalId,'text',$raw);
            $out=DirectAgentChat::handle((string)$route['slug'],(int)$session['id'],$text);MemoryService::maybeRememberOwnerPreference((int)$session['id'],$text);
            $reply=trim((string)($out['reply']??''));$closing=(bool)($out['close']??false);
            if(!(int)$session['identity_announced']){$reply='أنا '.$route['display_name'].'.'.($reply!==''?"
".$reply:'');db()->prepare('UPDATE conversation_sessions SET identity_announced=1 WHERE id=?')->execute([$session['id']]);}
            if($closing){$reply.=($reply!==''?"
":'').'تحت أمرك دايمًا يا باشمهندس. '.$route['display_name'];self::closeRoute($channel);ConversationService::close((int)$session['id']);}
            if($reply!=='')self::recordAndSend($conv,$channel,$provider,$from,$reply,(string)$route['slug']);
            return array_replace($out,['reply'=>$reply,'conversation_id'=>(int)$conv['id'],'message_id'=>$mid,'owner'=>true,'direct_agent'=>(string)$route['slug']]);
        }
        if($owner&&self::explicitRamy($text))self::closeRoute($channel);
        $conv=$owner?ConversationService::ownerConversation($channel,$provider):ConversationService::customerConversation($channel,$provider,self::digits($from),self::digits($from));
        if(!$owner&&!empty($conv['customer_id'])&&trim((string)($raw['_contact_name']??''))!==''){
            try{$n=pb_substr(trim((string)$raw['_contact_name']),0,190);db()->prepare("UPDATE customers SET display_name=CASE WHEN display_name IS NULL OR display_name='' THEN ? ELSE display_name END,updated_at=NOW() WHERE id=?")->execute([$n,(int)$conv['customer_id']]);}catch(Throwable){}
        }
        $sender=$owner?'owner':'customer';$mid=ConversationService::message((int)$conv['id'],$channel,$provider,'inbound',$sender,$owner?'1':self::digits($from),'agent','ramy',$text,$externalId,'text',$raw);
        Audit::log($sender,$owner?'1':self::digits($from),'message.inbound','message',(string)$mid,'verified',$conv['project_id']?(int)$conv['project_id']:null,$conv['task_id']?(int)$conv['task_id']:null,['channel'=>$channel,'provider'=>$provider]);
        if(!$owner){
            $blocked=(string)($conv['status']??'')==='blocked';
            if(!$blocked&&!empty($conv['customer_id'])){$bq=db()->prepare("SELECT status FROM customers WHERE id=?");$bq->execute([(int)$conv['customer_id']]);$blocked=(string)$bq->fetchColumn()==='blocked';}
            if($blocked){if((string)($conv['status']??'')!=='blocked')db()->prepare("UPDATE conversations SET status='blocked',updated_at=NOW() WHERE id=?")->execute([(int)$conv['id']]);return ['ok'=>true,'blocked'=>true,'reply'=>'','conversation_id'=>(int)$conv['id'],'message_id'=>$mid,'owner'=>false];}
            // A real customer reply re-opens Meta's customer-service window. Flush any exact messages queued while it was closed.
            if($channel==='whatsapp'&&$provider==='meta'&&!empty($conv['customer_id'])&&setting('whatsapp.auto_resume_pending','1')!=='0'){
                try{self::flushPendingCustomerMessages((int)$conv['customer_id'],$conv);}catch(Throwable $e){$detail=RamiErrorAdvisor::ownerText($e,'whatsapp_auto_resume');Notifications::add('warning','communications','تعذر إرسال الرسائل المنتظرة',$detail,'conversation',(string)$conv['id']);}
            }
        }
        try{$out=$owner?RamiOrchestrator::owner((int)$conv['session_id'],$text):CustomerDesk::handle((int)$conv['id'],$text);}catch(Throwable $e){
            $safe=AdminUi::humanError(Security::redactSecrets($e->getMessage(),220));$ownerError=$owner?RamiErrorAdvisor::ownerText($e,'runtime_recovery'):'';
            try{Notifications::add('critical','communications',$owner?'تعذر تشغيل رامي':'تعذر معالجة رسالة العميل',$owner?$ownerError:$safe,'conversation',(string)$conv['id']);}catch(Throwable){}
            $out=['ok'=>false,'reply'=>$owner?$ownerError.'
ما سجلتش العملية كنجاح.':'تم استلام رسالتك، لكن حدث عطل داخلي أثناء المعالجة.','action'=>'runtime_recovery'];
        }
        if($owner)MemoryService::maybeRememberOwnerPreference((int)$conv['session_id'],$text);
        $reply=trim((string)($out['reply']??''));if($reply!=='')self::recordAndSend($conv,$channel,$provider,$from,$reply);
        if(random_int(1,5)===1)ContextEngine::refreshSummary((int)$conv['session_id']);
        return $out+['conversation_id'=>(int)$conv['id'],'message_id'=>$mid,'owner'=>$owner];
    }
    public static function dashboard(string $text):array{Audit::setActor('owner','1');$conv=ConversationService::ownerConversation('dashboard','internal');$mid=ConversationService::message((int)$conv['id'],'dashboard','internal','inbound','owner','1','agent','ramy',$text,'','text',[]);try{$out=RamiOrchestrator::owner((int)$conv['session_id'],$text);}catch(Throwable $e){$detail=RamiErrorAdvisor::ownerText($e,'runtime_recovery');try{Notifications::add('critical','ramy','تعذر تشغيل رامي',$detail,'conversation',(string)$conv['id']);}catch(Throwable){}$out=['ok'=>false,'reply'=>$detail.'
ما سجلتش العملية كنجاح.','action'=>'runtime_recovery'];}MemoryService::maybeRememberOwnerPreference((int)$conv['session_id'],$text);$reply=trim((string)($out['reply']??''));if($reply!=='')ConversationService::message((int)$conv['id'],'dashboard','internal','outbound','agent','ramy','owner','1',$reply,'','text',[]);if(random_int(1,4)===1)ContextEngine::refreshSummary((int)$conv['session_id']);return $out+['conversation_id'=>(int)$conv['id'],'message_id'=>$mid];}
    public static function dashboardAgent(string $slug,string $text,bool $teamRoom=false):array{Audit::setActor('owner','1');$a=AgentService::assertRunnable(AgentService::bySlug($slug));if(!$teamRoom&&$slug!=='ramy'&&!in_array((string)$a['owner_communication'],['dashboard_only','dashboard_whatsapp'],true))throw new RuntimeException('agent_owner_contact_not_allowed');self::requireAgentReceiveOwnerChannel($a,'dashboard');$s=ConversationService::agentOwnerSession($slug);$conv=self::conversationForSession((int)$s['id'],'dashboard','internal');$mid=ConversationService::message((int)$conv['id'],'dashboard','internal','inbound','owner','1','agent',$slug,$text);$out=DirectAgentChat::handle($slug,(int)$s['id'],$text);MemoryService::maybeRememberOwnerPreference((int)$s['id'],$text);$reply=trim((string)$out['reply']);if(!(int)$s['identity_announced']){$reply='أنا '.$a['display_name'].'.'.($reply!==''?"\n".$reply:'');db()->prepare('UPDATE conversation_sessions SET identity_announced=1 WHERE id=?')->execute([$s['id']]);}if(!empty($out['close'])){$reply.=($reply!==''?"\n":'').'تحت أمرك دايمًا يا باشمهندس. '.$a['display_name'];ConversationService::close((int)$s['id']);}if($reply!=='')ConversationService::message((int)$conv['id'],'dashboard','internal','outbound','agent',$slug,'owner','1',$reply);return array_replace($out,['reply'=>$reply,'message_id'=>$mid,'session_id'=>(int)$s['id']]);}
    private static function conversationForSession(int $sessionId,string $channel,string $provider):array{$q=db()->prepare("SELECT * FROM conversations WHERE session_id=? AND channel_key=? AND provider=? AND status='open' ORDER BY id DESC LIMIT 1");$q->execute([$sessionId,$channel,$provider]);$c=$q->fetch();if($c)return $c;db()->prepare("INSERT INTO conversations(session_id,channel_key,provider,status) VALUES (?,?,?,'open')")->execute([$sessionId,$channel,$provider]);return ConversationService::conversation((int)db()->lastInsertId());}
    private static function recordAndSend(array $conv,string $channel,string $provider,string $to,string $body,string $senderSlug='ramy'):bool{
        $result=[];$eid='';
        try{
            if($provider==='meta'&&$channel==='whatsapp'){$result=MetaClient::sendText($to,$body);$eid=(string)($result['messages'][0]['id']??'');}
            elseif($provider==='twilio'&&in_array($channel,['sms','whatsapp'],true)){$result=TwilioClient::send(self::e164($to),$body,$channel);$eid=(string)($result['sid']??'');}
            elseif($provider==='generic_bridge'&&$channel==='generic_message'){$result=GenericMessageClient::send($to,$body);$eid=(string)($result['id']??$result['message_id']??'');}
            elseif($provider==='email'&&$channel==='email'){$subject=(string)setting('communications.email_subject','متابعة مشروعك — شركة المتر');$result=EmailConnector::send($to,$subject,$body);$eid=(string)($result['id']??'');}
            else throw new RuntimeException('outbound_provider_unsupported');
            $raw=$result;if($provider==='meta'&&$channel==='whatsapp')$raw['_transport']='free_text';elseif($provider==='email'&&$channel==='email')$raw['_transport']='email';
            $mid=ConversationService::message((int)$conv['id'],$channel,$provider,'outbound','agent',$senderSlug,$conv['customer_id']?'customer':'owner',$to,$body,$eid,'text',$raw);
            $state=(($provider==='meta'&&$channel==='whatsapp')||($provider==='email'&&$channel==='email'))?'accepted':'sent';
            db()->prepare('UPDATE messages SET status=? WHERE id=?')->execute([$state,$mid]);
            self::event($channel,$mid,$state,$state,$raw);return true;
        }catch(Throwable $e){
            $mid=ConversationService::message((int)$conv['id'],$channel,$provider,'outbound','agent',$senderSlug,$conv['customer_id']?'customer':'owner',$to,$body,'','text',['error'=>$e->getMessage(),'_transport'=>'free_text']);
            db()->prepare("UPDATE messages SET status='failed' WHERE id=?")->execute([$mid]);self::event($channel,$mid,'send_failed','failed',['error'=>$e->getMessage()]);
            Notifications::add('critical','communications','فشل إرسال رسالة',AdminUi::humanError($e->getMessage()),'message',(string)$mid);return false;
        }
    }
    private static function event(string $channel,?int $messageId,string $type,string $state,array $details=[]):void{$q=db()->prepare('SELECT id FROM communication_channels WHERE channel_key=? LIMIT 1');$q->execute([$channel]);$cid=$q->fetchColumn()?:null;db()->prepare('INSERT INTO communication_events(channel_id,message_id,event_type,state,details_json) VALUES (?,?,?,?,?)')->execute([$cid,$messageId&&$messageId>0?$messageId:null,$type,$state,$details?j($details):null]);}
    public static function ramyDashboardUpdate(string $body,?int $projectId=null,?int $taskId=null):int{$body=trim($body);if($body==='')return 0;$ramy=AgentService::assertRunnable(AgentService::bySlug('ramy'));$prev=Audit::actor();Audit::setActor('agent',(string)$ramy['id']);try{$conv=ConversationService::ownerConversation('dashboard','internal');$mid=ConversationService::message((int)$conv['id'],'dashboard','internal','outbound','agent','ramy','owner','1',$body,'','text',[]);Audit::log('agent',(string)$ramy['id'],'ramy.owner_update','message',(string)$mid,'verified',$projectId,$taskId);return $mid;}finally{Audit::setActor((string)$prev['type'],(string)$prev['id']);}}
    public static function outboundOwner(string $body,string $preferred=''):array{$to=self::ownerPhone();if($to==='')throw new RuntimeException('owner_phone_missing');$preferred=$preferred?:setting('communications.default_owner_channel','whatsapp');$provider=match($preferred){'whatsapp'=>'meta','generic_message'=>'generic_bridge',default=>'twilio'};$conv=ConversationService::ownerConversation($preferred,$provider);$ok=self::recordAndSend($conv,$preferred,$provider,$to,$body);return ['sent'=>$ok,'channel'=>$preferred];}
    public static function testWhatsAppTemplateToOwner():array{
        $to=self::ownerPhone();if($to==='')throw new RuntimeException('owner_phone_missing');$name=WhatsAppPolicy::templateName();$lang=WhatsAppPolicy::templateLanguage();if($name==='')throw new RuntimeException('meta_template_required_outside_customer_window');
        $conv=ConversationService::ownerConversation('whatsapp','meta');$result=MetaClient::sendTemplate($to,$name,$lang);$eid=(string)($result['messages'][0]['id']??'');if($eid==='')throw new RuntimeException('meta_send_no_message_id');
        $raw=$result+['_transport'=>'template','_template_name'=>$name,'_template_language'=>$lang,'_template_test'=>true];$body='[اختبار قالب واتساب: '.$name.']';
        $mid=ConversationService::message((int)$conv['id'],'whatsapp','meta','outbound','agent','ramy','owner',$to,$body,$eid,'text',$raw);db()->prepare("UPDATE messages SET status='accepted' WHERE id=?")->execute([$mid]);self::event('whatsapp',$mid,'template_test_accepted','accepted',$raw);
        try{put_setting('whatsapp.template_test_last_accepted_at',now_utc());put_setting('whatsapp.template_test_last_message_id',$eid);}catch(Throwable){}
        return ['accepted'=>true,'message_id'=>$eid,'message_db_id'=>$mid,'template'=>$name,'language'=>$lang];
    }
    public static function outboundCustomer(int $customerId,string $body):array{
        $body=trim($body);if($body==='')throw new RuntimeException('message_body_required');
        $cq=db()->prepare('SELECT * FROM customers WHERE id=?');$cq->execute([$customerId]);$customer=$cq->fetch();if(!$customer)throw new RuntimeException('customer_not_found');
        if((string)($customer['status']??'')==='blocked')throw new RuntimeException('customer_blocked');

        $q=db()->prepare("SELECT * FROM conversations WHERE customer_id=? AND channel_key IN ('whatsapp','sms','email','generic_message') AND status='open' ORDER BY id DESC LIMIT 1");$q->execute([$customerId]);$conv=$q->fetch();
        $supported=['whatsapp','sms','email','generic_message'];$channel=(string)($conv['channel_key']??$customer['primary_channel']??'');
        if(!in_array($channel,$supported,true)){
            if(ConversationService::normalizePhone((string)($customer['phone']??''))!=='')$channel='whatsapp';
            elseif(filter_var((string)($customer['email']??''),FILTER_VALIDATE_EMAIL))$channel='email';
            else $channel='';
        }
        if($channel==='email'){
            $provider='email';$to=trim((string)($customer['email']??''));
            if(!filter_var($to,FILTER_VALIDATE_EMAIL))$channel='';
        }else{
            $provider=(string)($conv['provider']??match($channel){'whatsapp'=>'meta','generic_message'=>'generic_bridge',default=>'twilio'});
            $to=ConversationService::normalizePhone((string)($customer['phone']??''));
            if($to===''&&$channel==='generic_message')$to=trim((string)($customer['external_ref']??''));
            if($to===''&&$conv&&$channel!=='email')$to=ConversationService::normalizePhone((string)($conv['external_thread_id']??''));
            if($to===''&&in_array($channel,['whatsapp','sms'],true))$channel='';
        }

        if($channel==='')return self::manualCustomerContactTask($customerId,$body,$customer);
        if(!$conv||$conv['channel_key']!==$channel||$conv['provider']!==$provider)$conv=ConversationService::customerConversationFor($customerId,$channel,$provider,$to);
        if($channel==='whatsapp'&&(string)($customer['phone']??'')!==$to)db()->prepare("UPDATE customers SET phone=?,primary_channel='whatsapp',updated_at=NOW() WHERE id=?")->execute([$to,$customerId]);
        elseif($channel==='email'&&(string)($customer['primary_channel']??'')!==$channel)db()->prepare("UPDATE customers SET primary_channel='email',updated_at=NOW() WHERE id=?")->execute([$customerId]);

        if($channel==='whatsapp'&&$provider==='meta'&&!WhatsAppPolicy::serviceWindowOpen($customerId)){
            $pendingId=WhatsAppPolicy::queue($customerId,(int)$conv['id'],$body,'outside_24h_window');
            $templateSubmission=WhatsAppPolicy::refreshTemplateState(true);
            if(!WhatsAppPolicy::templateReady()){
                $state=strtoupper((string)($templateSubmission['state']??''));$name=(string)($templateSubmission['name']??setting('whatsapp.template_submission_name',''));
                if($state==='PENDING')$reason='نافذة خدمة العميل 24 ساعة مقفولة. حفظت الرسالة الأصلية، وقالب الافتتاح '.($name!==''?'«'.$name.'» ':'').'تحت مراجعة Meta حاليًا؛ لن أدّعي إن الرسالة اتبعت قبل ما القالب يبقى Approved.';
                else $reason='نافذة خدمة العميل 24 ساعة مقفولة، ولا يوجد قالب WhatsApp Approved صالح لبدء المحادثة. حفظت الرسالة ولن أرسل نصًا حرًا يعرف مسبقًا أنه سيفشل.'.(!empty($templateSubmission['error'])?' سبب تعذر تجهيز القالب تلقائيًا: '.$templateSubmission['error']:'' );
                Notifications::add('warning','communications',$state==='PENDING'?'قالب WhatsApp تحت مراجعة Meta':'رسالة العميل محفوظة وتحتاج قالب WhatsApp',$reason,'customer',(string)$customerId);
                return ['sent'=>false,'accepted'=>false,'queued'=>true,'requires_template'=>true,'template_state'=>$state?:'MISSING','template_name'=>$name?:null,'pending_id'=>$pendingId,'channel'=>$channel,'provider'=>$provider,'reason'=>$reason];
            }
            if(WhatsAppPolicy::waitingForReply($customerId)){
                return ['sent'=>false,'accepted'=>true,'queued'=>true,'awaiting_reply'=>true,'template_already_sent'=>true,'pending_id'=>$pendingId,'channel'=>$channel,'provider'=>$provider,'reason'=>'يوجد قالب افتتاح أُرسل للعميل بالفعل وننتظر رده لفتح نافذة الـ24 ساعة؛ الرسالة الجديدة محفوظة في الطابور.'];
            }
            return self::sendOpeningTemplate($conv,$customerId,$to,$pendingId);
        }
        $ok=self::recordAndSend($conv,$channel,$provider,$to,$body);
        if($ok){try{$oq=db()->prepare("SELECT id FROM opportunities WHERE customer_id=? AND status IN ('approved','contacted','negotiating') ORDER BY id DESC LIMIT 1");$oq->execute([$customerId]);$oid=(int)($oq->fetchColumn()?:0);if($oid)self::markOpportunityContacted($oid);}catch(Throwable){}}
        return ['sent'=>$ok,'accepted'=>$ok,'channel'=>$channel,'provider'=>$provider,'service_window_open'=>$channel==='whatsapp'?true:null];
    }

    private static function markOpportunityContacted(int $opportunityId):void{
        try{OpportunityService::markContacted($opportunityId);}catch(Throwable){}
    }

    private static function manualCustomerContactTask(int $customerId,string $body,array $customer):array{
        $q=db()->prepare('SELECT id,project_id,contact_methods_json,source_url FROM opportunities WHERE customer_id=? ORDER BY id DESC LIMIT 1');$q->execute([$customerId]);$opp=$q->fetch()?:[];
        $methods=[];try{$methods=json_decode((string)($opp['contact_methods_json']??'[]'),true,512,JSON_THROW_ON_ERROR)?:[];}catch(Throwable){}
        $projectId=(int)($opp['project_id']??0);$ramy=(int)AgentService::bySlug('ramy')['id'];$title='تواصل يدوي مع العميل #'.$customerId;
        $q=db()->prepare("SELECT id FROM tasks WHERE assigned_agent_id=? AND title=? AND status NOT IN ('completed','cancelled','failed') ORDER BY id DESC LIMIT 1");$q->execute([$ramy,$title]);$taskId=(int)($q->fetchColumn()?:0);
        if(!$taskId){$desc="لا توجد قناة إرسال API مباشرة للعميل. استخدم وسيلة التواصل العامة المتاحة وسجل النتيجة كـEvidence.\nالرسالة المقترحة:\n".$body."\nوسائل التواصل: ".j($methods).(!empty($opp['source_url'])?"\nرابط المصدر: ".$opp['source_url']:'');$taskId=TaskService::create('ramy',$title,$desc,$projectId?:null,['customer_id'=>$customerId,'contact_methods'=>$methods,'source_url'=>$opp['source_url']??null,'manual_contact'=>true],'high',true,null,'system','communications',false);try{TaskService::queueIfReady($taskId);}catch(Throwable){}}
        Notifications::add('warning','communications','التواصل يحتاج قناة خارجية','لا توجد قناة API مباشرة لهذا العميل؛ تم إنشاء مهمة رامي #'.$taskId.' بدل ادعاء إرسال الرسالة.','customer',(string)$customerId);
        return ['sent'=>false,'accepted'=>false,'requires_manual_contact'=>true,'task_id'=>$taskId,'contact_methods'=>$methods,'channel'=>'manual','provider'=>'none'];
    }

    private static function sendOpeningTemplate(array $conv,int $customerId,string $to,int $pendingId):array{
        $name=WhatsAppPolicy::templateName();$lang=WhatsAppPolicy::templateLanguage();if($name==='')throw new RuntimeException('meta_template_required_outside_customer_window');
        try{
            $result=MetaClient::sendTemplate($to,$name,$lang);$eid=(string)($result['messages'][0]['id']??'');
            $raw=$result+['_transport'=>'template','_template_name'=>$name,'_template_language'=>$lang,'_pending_id'=>$pendingId];
            $label='[قالب واتساب معتمد: '.$name.']';
            $mid=ConversationService::message((int)$conv['id'],'whatsapp','meta','outbound','agent','ramy','customer',$to,$label,$eid,'text',$raw);
            db()->prepare("UPDATE messages SET status='accepted' WHERE id=?")->execute([$mid]);self::event('whatsapp',$mid,'template_accepted','accepted',$raw);
            WhatsAppPolicy::markWaiting($pendingId,$eid);
            Notifications::add('info','communications','تم إرسال قالب افتتاح للعميل','Meta قبل قالب '.$name.'. الرسالة الأصلية محفوظة وسترسل تلقائيًا بعد أول رد من العميل.','customer',(string)$customerId);
            return ['sent'=>true,'accepted'=>true,'queued'=>true,'template'=>true,'awaiting_reply'=>true,'pending_id'=>$pendingId,'message_id'=>$eid,'channel'=>'whatsapp','provider'=>'meta'];
        }catch(Throwable $e){WhatsAppPolicy::markRetryPending($pendingId,$e->getMessage(),true);throw $e;}
    }

    private static function flushPendingCustomerMessages(int $customerId,array $conv):array{
        if($customerId<1||!WhatsAppPolicy::serviceWindowOpen($customerId))return ['sent'=>0,'failed'=>0];
        $rows=WhatsAppPolicy::pending($customerId,10);$sent=0;$failed=0;
        $cq=db()->prepare('SELECT phone,status FROM customers WHERE id=?');$cq->execute([$customerId]);$customer=$cq->fetch();if(!$customer||(string)$customer['status']==='blocked')return ['sent'=>0,'failed'=>0];
        $to=ConversationService::normalizePhone((string)$customer['phone']);if($to==='')return ['sent'=>0,'failed'=>count($rows)];
        foreach($rows as $row){
            WhatsAppPolicy::markSending((int)$row['id']);
            if(self::recordAndSend($conv,'whatsapp','meta',$to,(string)$row['body_text'])){WhatsAppPolicy::markSent((int)$row['id']);$sent++;}
            else{WhatsAppPolicy::markRetryPending((int)$row['id'],'queued_message_send_failed',false);$failed++;}
        }
        if($sent>0)Notifications::add('success','communications','تم إرسال الرسائل المنتظرة للعميل','العميل رد وفتح نافذة WhatsApp؛ تم إرسال '.($sent).' رسالة كانت محفوظة في الطابور.','customer',(string)$customerId);
        return ['sent'=>$sent,'failed'=>$failed];
    }

    public static function recoverWhatsAppQueue(int $limitCustomers=6):array{
        if(!MetaClient::configured())throw new RuntimeException('meta_not_configured');
        $recoveredFailed=WhatsAppPolicy::recoverFailedQueue();$template=WhatsAppPolicy::refreshTemplateState(true);$templateReady=!empty($template['ready']);$waba=(string)($template['waba_id']??'');
        $summary=['recovered_failed'=>$recoveredFailed,'waba_id'=>$waba!==''?$waba:null,'template_ready'=>$templateReady,'template_state'=>$template['state']??null,'template_name'=>$template['name']??null,'template_auto_selected'=>!empty($template['selected']),'template_created'=>!empty($template['created']),'template_error'=>$template['error']??null,'customers'=>0,'opened_window_sent'=>0,'templates_sent'=>0,'waiting_reply'=>0,'failed'=>0,'details'=>[]];
        foreach(WhatsAppPolicy::pendingCustomers($limitCustomers) as $pc){$cid=(int)($pc['customer_id']??0);if($cid<1)continue;$summary['customers']++;
            $q=db()->prepare("SELECT * FROM conversations WHERE customer_id=? AND channel_key='whatsapp' AND provider='meta' ORDER BY id DESC LIMIT 1");$q->execute([$cid]);$conv=$q->fetch();if(!$conv){$summary['failed']++;$summary['details'][]=['customer_id'=>$cid,'state'=>'missing_conversation'];continue;}
            if(WhatsAppPolicy::serviceWindowOpen($cid)){$r=self::flushPendingCustomerMessages($cid,$conv);$summary['opened_window_sent']+=(int)($r['sent']??0);$summary['failed']+=(int)($r['failed']??0);continue;}
            if(WhatsAppPolicy::waitingForReply($cid)){$summary['waiting_reply']++;continue;}
            if(!$templateReady){$summary['details'][]=['customer_id'=>$cid,'state'=>'needs_template'];continue;}
            try{$cq=db()->prepare('SELECT phone,external_ref,status FROM customers WHERE id=?');$cq->execute([$cid]);$cust=$cq->fetch()?:[];if((string)($cust['status']??'')==='blocked'){continue;}$to=ConversationService::normalizePhone((string)($cust['phone']??''));if($to==='')$to=ConversationService::normalizePhone((string)($cust['external_ref']??''));if($to==='')throw new RuntimeException('customer_phone_missing');$rows=WhatsAppPolicy::pending($cid,1);if(!$rows)continue;self::sendOpeningTemplate($conv,$cid,$to,(int)$rows[0]['id']);$summary['templates_sent']++;}
            catch(Throwable $e){$summary['failed']++;$summary['details'][]=['customer_id'=>$cid,'state'=>'template_failed','error'=>AdminUi::humanError($e->getMessage())];}
        }
        return $summary;
    }

    public static function handleMetaDeliveryStatus(array $st):void{
        $externalId=trim((string)($st['id']??''));$status=trim((string)($st['status']??''));if($externalId===''||$status==='')return;
        $q=db()->prepare("SELECT m.*,c.customer_id,c.id conversation_id,c.channel_key,c.provider FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.provider='meta' AND m.external_id=? LIMIT 1");$q->execute([$externalId]);$m=$q->fetch();
        if($m){$current=(string)($m['status']??'');$rank=['accepted'=>0,'queued'=>0,'sent'=>1,'delivered'=>2,'read'=>3];$effective=$status;if($status!=='failed'&&isset($rank[$current],$rank[$status])&&$rank[$status]<$rank[$current])$effective=$current;db()->prepare('UPDATE messages SET status=? WHERE id=?')->execute([$effective,(int)$m['id']]);self::event('whatsapp',(int)$m['id'],'delivery_status',$status,(array)($st['raw']??[]));}
        else{self::event('whatsapp',0,'delivery_status',$status,(array)($st['raw']??[]));return;}
        $pending=WhatsAppPolicy::byTemplateMessageId($externalId);$rawMsg=[];try{$rawMsg=json_decode((string)($m['raw_json']??''),true,512,JSON_THROW_ON_ERROR)?:[];}catch(Throwable){}
        if(($pending||(string)($rawMsg['_transport']??'')==='template')&&in_array($status,['sent','delivered','read'],true)){
            $tpl=(string)($rawMsg['_template_name']??'');$lang=(string)($rawMsg['_template_language']??'');
            if($tpl!==''){
                try{put_setting('whatsapp.template_verified_name',$tpl);put_setting('whatsapp.template_verified_language',$lang?:WhatsAppPolicy::templateLanguage());put_setting('whatsapp.template_verified_delivery_at',now_utc());put_setting('whatsapp.template_submission_state','APPROVED');}catch(Throwable){}
            }
        }
        if($status==='failed'){
            $human=WhatsAppPolicy::humanFailure($st);$failure=WhatsAppPolicy::metaFailure($st);
            if($pending){
                $invalidTemplate=in_array((int)$failure['code'],[132001,132012,131008,131058],true);
                WhatsAppPolicy::markRetryPending((int)$pending['id'],$human,true,(int)$failure['code']===131058);
                if($invalidTemplate){
                    try{
                        $used=(string)($rawMsg['_template_name']??'');
                        $configured=trim((string)setting('whatsapp.customer_template_name',''));
                        if($used!==''&&($configured===''||hash_equals($used,$configured)||WhatsAppPolicy::isReservedTemplate($used))){
                            put_setting('whatsapp.customer_template_name','');
                            put_setting('whatsapp.template_submission_state',(int)$failure['code']===131058?'INVALID_SAMPLE':'INVALID');
                            put_setting('whatsapp.template_last_invalid_name',$used);
                            put_setting('whatsapp.template_last_failure_code',(string)(int)$failure['code']);
                        }
                        WhatsAppPolicy::refreshTemplateState(true);
                    }catch(Throwable){}
                }
                Notifications::add('critical','communications','فشل قالب WhatsApp للعميل',$human.' الرسالة الأصلية ما زالت محفوظة ويمكن إعادة المحاولة بعد إصلاح القالب/Meta.','message',(string)$m['id']);return;
            }
            if((int)$failure['code']===131047&&!empty($m['customer_id'])&&(string)($rawMsg['_transport']??'')!=='template'){
                $pid=WhatsAppPolicy::queue((int)$m['customer_id'],(int)$m['conversation_id'],(string)$m['body_text'],'meta_131047');
                $recovered=false;$templateState=WhatsAppPolicy::refreshTemplateState(true);
                if(!empty($templateState['ready'])&&!WhatsAppPolicy::waitingForReply((int)$m['customer_id'])){
                    try{$cq=db()->prepare('SELECT phone,external_ref FROM customers WHERE id=?');$cq->execute([(int)$m['customer_id']]);$cust=$cq->fetch()?:[];$to=ConversationService::normalizePhone((string)($cust['phone']??''));if($to==='')$to=ConversationService::normalizePhone((string)($cust['external_ref']??''));if($to!==''){self::sendOpeningTemplate(['id'=>(int)$m['conversation_id'],'customer_id'=>(int)$m['customer_id']],(int)$m['customer_id'],$to,$pid);$recovered=true;}}catch(Throwable $e){$human.=' كما تعذر إرسال القالب: '.AdminUi::humanError($e->getMessage());}
                }
                Notifications::add($recovered?'warning':'critical','communications',$recovered?'تم تحويل فشل WhatsApp لمسار القالب':'WhatsApp رفض الرسالة خارج نافذة 24 ساعة',$human.($recovered?' تم إرسال قالب افتتاح معتمد، والرسالة الأصلية محفوظة حتى يرد العميل.':' أضف اسم قالب افتتاح معتمد من صفحة الربط، والرسالة الأصلية محفوظة.'),'message',(string)$m['id']);
                return;
            }
            Notifications::add('critical','communications','فشل تسليم رسالة WhatsApp',$human,'message',(string)$m['id']);
        }
    }
    public static function voiceInput(string $from,string $speech,string $callSid,string $to='',string $channel='voice',string $provider='twilio',array $raw=[],string $agentSlug=''):array{
        $owner=self::isOwnerNumber($from)||self::isOwnerNumber($to);$thread=self::digits($from!==''?$from:($to!==''?$to:$callSid));
        $selected='ramy';
        if($owner){
            $requested=trim($agentSlug)!==''?trim($agentSlug):trim((string)($raw['agent_slug']??$raw['agent']['slug']??''));
            if($requested===''){try{$ca=WhatsAppCallingService::agentForCall($callSid);if($ca)$requested=(string)$ca['slug'];}catch(Throwable){}}
            if($requested==='')$requested=trim((string)setting('voice.owner_default_agent','ramy'))?:'ramy';
            try{$a=AgentService::assertRunnable(AgentService::bySlug($requested));self::requireAgentReceiveOwnerChannel($a,'dashboard');if(Permissions::agent((int)$a['id'],'whatsapp.call.receive'))$selected=(string)$a['slug'];}catch(Throwable){$selected='ramy';}
        }
        $conv=$owner?ConversationService::ownerConversation($channel,$provider):ConversationService::customerConversation($channel,$provider,$thread,self::digits($from));$payload=$raw?:['CallSid'=>$callSid];
        $mid=ConversationService::message((int)$conv['id'],$channel,$provider,'inbound',$owner?'owner':'customer',$owner?'1':self::digits($from),'agent',$selected,$speech,$callSid.':'.time(),'call_event',$payload+['routed_agent'=>$selected]);
        $out=$owner?DirectAgentChat::handle($selected,(int)$conv['session_id'],$speech):CustomerDesk::handle((int)$conv['id'],$speech);
        if($owner)MemoryService::maybeRememberOwnerPreference((int)$conv['session_id'],$speech);if(random_int(1,4)===1)ContextEngine::refreshSummary((int)$conv['session_id']);
        $reply=trim((string)($out['reply']??''))?:'تم استلام كلامك.';ConversationService::message((int)$conv['id'],$channel,$provider,'outbound','agent',$selected,$owner?'owner':'customer',$from,$reply,'','call_event',['routed_agent'=>$selected]);
        return ['reply'=>$reply,'conversation'=>$conv,'owner'=>$owner,'message_id'=>$mid,'agent_slug'=>$selected]+$out;
    }

    private static function requireAgentReceiveOwnerChannel(array $a,string $channel):array{$slug=(string)$a['slug'];if($slug==='ramy')return ['can_send_owner'=>1,'can_receive_owner'=>1,'can_start'=>1,'requires_ramy_approval'=>0,'rate_limit_per_hour'=>100];$q=db()->prepare('SELECT * FROM agent_channel_permissions WHERE agent_id=? AND channel_key=?');$q->execute([$a['id'],$channel]);$p=$q->fetch();if(!$p||!(int)$p['can_receive_owner'])throw new RuntimeException('agent_owner_receive_not_allowed');return $p;}
    private static function requireAgentOwnerChannel(array $a,string $channel,bool $starting=true):array{$slug=(string)$a['slug'];if($slug==='ramy')return ['can_send_owner'=>1,'can_receive_owner'=>1,'can_start'=>1,'requires_ramy_approval'=>0,'rate_limit_per_hour'=>100];$q=db()->prepare('SELECT * FROM agent_channel_permissions WHERE agent_id=? AND channel_key=?');$q->execute([$a['id'],$channel]);$p=$q->fetch();if(!$p||!(int)$p['can_send_owner']||($starting&&!(int)$p['can_start']))throw new RuntimeException('agent_owner_contact_not_allowed');if((int)$p['requires_ramy_approval'])throw new RuntimeException('ramy_approval_required');$q=db()->prepare("SELECT COUNT(*) FROM messages WHERE sender_type='agent' AND sender_ref=? AND channel_key=? AND direction='outbound' AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");$q->execute([$slug,$channel]);if((int)$q->fetchColumn()>=(int)$p['rate_limit_per_hour'])throw new RuntimeException('agent_channel_rate_limited');return $p;}
    public static function agentToOwner(string $slug,string $body,string $channel='dashboard',bool $closing=false,bool $emergency=false):array{$a=AgentService::assertRunnable(AgentService::bySlug($slug));Audit::setActor('agent',(string)$a['id']);$mode=(string)$a['owner_communication'];if($slug!=='ramy'){$allowed=match($channel){'whatsapp'=>in_array($mode,['whatsapp','dashboard_whatsapp','emergency_only'],true)&&$a['whatsapp_enabled'],'dashboard'=>in_array($mode,['dashboard_only','dashboard_whatsapp'],true),default=>false};if($mode==='emergency_only'&&!$emergency)throw new RuntimeException('agent_emergency_only');if(!$allowed)throw new RuntimeException('agent_owner_contact_not_allowed');self::requireAgentOwnerChannel($a,$channel,true);}$session=ConversationService::agentOwnerSession($slug);$text=trim($body);if($slug!=='ramy'&&!(int)$session['identity_announced']){$text='أنا '.$a['display_name'].'.'.($text!==''?"\n".$text:'');db()->prepare('UPDATE conversation_sessions SET identity_announced=1 WHERE id=?')->execute([$session['id']]);}if($closing&&$slug!=='ramy')$text.=($text!==''?"\n":'').'تحت أمرك دايمًا يا باشمهندس. '.$a['display_name'];if($channel==='dashboard'){$conv=self::conversationForSession((int)$session['id'],'dashboard','internal');ConversationService::message((int)$conv['id'],'dashboard','internal','outbound','agent',$slug,'owner','1',$text);try{TeamChatService::post('agent',$slug,'team',$text,'reply');}catch(Throwable){}Notifications::add('info','agents',$a['display_name'].' أرسل رسالة للمالك',$text,'agent',(string)$a['id']);}elseif($channel==='whatsapp'){$to=self::ownerPhone();$conv=self::conversationForSession((int)$session['id'],'whatsapp','meta');$ok=self::recordAndSend($conv,'whatsapp','meta',$to,$text,$slug);if(!$ok)throw new RuntimeException('owner_whatsapp_send_failed');self::activateRoute('whatsapp',(int)$a['id'],(int)$session['id']);}else throw new RuntimeException('agent_owner_channel_unsupported');if($closing){self::closeRoute($channel);ConversationService::close((int)$session['id']);}Audit::log('agent',(string)$a['id'],'agent.owner_message','conversation_session',(string)$session['id'],'executed',null,null,['channel'=>$channel,'closing'=>$closing]);return ['sent'=>true,'session_id'=>(int)$session['id'],'text'=>$text];}
    public static function agentMessage(string $fromSlug,string $toSlug,string $body,?int $taskId=null,?int $projectId=null,array $meta=[]):int{$from=AgentService::assertRunnable(AgentService::bySlug($fromSlug));Audit::setActor('agent',(string)$from['id']);$to=AgentService::assertRunnable(AgentService::bySlug($toSlug));$q=db()->prepare('SELECT * FROM agent_relationships WHERE from_agent_id=? AND to_agent_id=?');$q->execute([$from['id'],$to['id']]);$r=$q->fetch();if(!$r||!(int)$r['can_message'])throw new RuntimeException('agent_to_agent_not_allowed');if((int)$r['requires_manager_approval']&&$fromSlug!=='ramy')throw new RuntimeException('ramy_approval_required');$key='agents:'.$fromSlug.':'.$toSlug.':'.($taskId?:0);$q=db()->prepare("SELECT * FROM conversation_sessions WHERE subject_type='agent_pair' AND subject_key=? AND status='open' ORDER BY id DESC LIMIT 1");$q->execute([$key]);$s=$q->fetch();if(!$s){if(!(int)$r['can_start'])throw new RuntimeException('agent_start_conversation_not_allowed');db()->prepare("INSERT INTO conversation_sessions(subject_type,subject_key,status,last_agent_id,active_project_id,active_task_id) VALUES ('agent_pair',?,'open',?,?,?)")->execute([$key,$from['id'],$projectId,$taskId]);$sid=(int)db()->lastInsertId();}else $sid=(int)$s['id'];$conv=self::conversationForSession($sid,'internal','internal');$mid=ConversationService::message((int)$conv['id'],'internal','internal','outbound','agent',$fromSlug,'agent',$toSlug,trim($body));Audit::log('agent',(string)$from['id'],'agent.message','message',(string)$mid,'executed',$projectId,$taskId,['to'=>$toSlug]);try{TeamChatService::agentMessage($fromSlug,$toSlug,trim($body),$taskId,$projectId,$meta);}catch(Throwable){}return $mid;}
    public static function agentCommand(string $fromSlug,string $toSlug,string $body,?int $taskId=null,?int $projectId=null):array{$mid=self::agentMessage($fromSlug,$toSlug,$body,$taskId,$projectId,['already_dispatched'=>1,'command_bus'=>1]);$execution=TeamChatService::dispatchAgentCommand($fromSlug,$toSlug,$body,$taskId,$projectId);return ['message_id'=>$mid,'execution'=>$execution,'primary_task'=>$execution['primary_task']??null,'summary'=>$execution['summary']??'تم تمرير التكليف.'];}
}
