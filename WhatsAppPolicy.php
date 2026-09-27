<?php
declare(strict_types=1);

final class WhatsAppPolicy {
    public static function windowHours(): int {
        return max(1,min(24,(int)setting('whatsapp.customer_service_window_hours','24')));
    }

    public static function lastCustomerInboundAt(int $customerId): ?string {
        if($customerId<1)return null;
        $q=db()->prepare("SELECT m.created_at FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.customer_id=? AND m.channel_key='whatsapp' AND m.provider='meta' AND m.direction='inbound' AND m.sender_type='customer' ORDER BY m.id DESC LIMIT 1");
        $q->execute([$customerId]);
        $v=$q->fetchColumn();
        return $v!==false?(string)$v:null;
    }

    public static function serviceWindowOpen(int $customerId): bool {
        if($customerId<1)return false;
        $hours=self::windowHours();
        $q=db()->prepare("SELECT 1 FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.customer_id=? AND m.channel_key='whatsapp' AND m.provider='meta' AND m.direction='inbound' AND m.sender_type='customer' AND m.created_at>=DATE_SUB(NOW(),INTERVAL ".$hours." HOUR) ORDER BY m.id DESC LIMIT 1");
        $q->execute([$customerId]);return (bool)$q->fetchColumn();
    }

    public static function isReservedTemplate(string $name): bool {
        $name=pb_strtolower(trim($name));
        if($name==='')return false;
        // Meta's hello_world is a public test-number sample and must never be used for production customer outreach.
        if($name==='hello_world')return true;
        return preg_match('/^(?:sample|demo)_/i',$name)===1;
    }

    public static function templateName(): string {
        $name=trim((string)setting('whatsapp.customer_template_name',''));
        if(self::isReservedTemplate($name))return '';
        return preg_match('/^[a-z0-9_]{1,190}$/',$name)?$name:'';
    }

    public static function templateLanguage(): string {
        $lang=trim((string)setting('whatsapp.customer_template_language','ar'));
        return preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z]{2})?$/',$lang)?$lang:'ar';
    }

    public static function templateReady(): bool {
        return self::templateName()!=='';
    }

    public static function autoContactEnabled(): bool {
        return setting('whatsapp.auto_contact_flow','1')!=='0';
    }

    public static function refreshTemplateState(bool $allowCreate=true): array {
        $out=['ready'=>false,'state'=>'MISSING','name'=>self::templateName()?:null,'language'=>self::templateLanguage(),'waba_id'=>null,'approved_count'=>0,'selected'=>false,'created'=>false,'error'=>null];
        if(!MetaClient::configured()){$out['state']='META_NOT_CONFIGURED';return $out;}
        $waba=MetaClient::recoverWabaIdFromRecentWebhook();$out['waba_id']=$waba!==''?$waba:null;
        if($waba===''){$out['state']='WABA_MISSING';return $out;}
        $approved=[];$current=self::templateName();$lang=self::templateLanguage();
        try{$approved=MetaClient::approvedTemplates();$out['approved_count']=count($approved);}
        catch(Throwable $e){
            $raw=pb_substr(Security::redactSecrets($e->getMessage()),0,300);$out['state']='CHECK_FAILED';$out['error_code']=$raw;$out['error']=AdminUi::humanError($raw);
            // A token can legitimately have messaging permission without template-management permission.
            // If the owner supplied an exact approved template name, allow a real send attempt to validate it instead of deadlocking the queue.
            if($current!==''){$out['ready']=true;$out['state']='MANUAL_TEMPLATE_SEND_TEST';$out['name']=$current;$out['language']=$lang;}
            return $out;
        }
        if($current!==''){
            foreach($approved as $t){
                if((string)($t['name']??'')===$current&&(string)($t['language']??'')===$lang){put_setting('whatsapp.template_submission_state','APPROVED');put_setting('whatsapp.template_last_verified_at',now_utc());$out['ready']=true;$out['state']='APPROVED';$out['name']=$current;return $out;}
            }
            put_setting('whatsapp.customer_template_name','');put_setting('whatsapp.template_last_invalid_name',$current);put_setting('whatsapp.template_last_verified_at',now_utc());$out['name']=null;
        }
        if(self::autoTemplateSelectEnabled()&&self::autoSelectTemplate()){
            $out['ready']=true;$out['selected']=true;$out['state']='APPROVED';$out['name']=self::templateName();$out['language']=self::templateLanguage();put_setting('whatsapp.template_submission_state','APPROVED');put_setting('whatsapp.template_last_verified_at',now_utc());return $out;
        }
        if($allowCreate&&self::autoContactEnabled()&&setting('whatsapp.auto_create_template','1')!=='0'){
            try{$r=MetaClient::ensureOpeningTemplate();$state=strtoupper((string)($r['state']??'MISSING'));$out['state']=$state;$out['name']=($r['name']??null)?:null;$out['language']=(string)($r['language']??$out['language']);$out['created']=!empty($r['created']);$out['ready']=$state==='APPROVED'&&self::templateReady();put_setting('whatsapp.template_last_verified_at',now_utc());return $out;}
            catch(Throwable $e){$raw=pb_substr(Security::redactSecrets($e->getMessage()),0,300);$out['state']='CREATE_FAILED';$out['error_code']=$raw;$out['error']=AdminUi::humanError($raw);return $out;}
        }
        put_setting('whatsapp.template_submission_state',(string)$out['state']);put_setting('whatsapp.template_last_verified_at',now_utc());
        return $out;
    }

    public static function autoTemplateSelectEnabled(): bool {
        return setting('whatsapp.auto_template_select','1')!=='0';
    }

    public static function autoSelectTemplate(): bool {
        if(self::templateReady())return true;
        if(!self::autoTemplateSelectEnabled())return false;
        try{$templates=MetaClient::approvedTemplates();}catch(Throwable){return false;}
        if(!$templates)return false;
        $want=strtolower(self::templateLanguage());$best=null;$bestScore=-1;
        foreach($templates as $t){
            if(!is_array($t))continue;$name=trim((string)($t['name']??''));$lang=trim((string)($t['language']??''));if(!preg_match('/^[a-z0-9_]{1,190}$/',$name)||self::isReservedTemplate($name))continue;
            $blob=j((array)($t['components']??[]));if(str_contains($blob,'{{'))continue; // zero-variable templates only: no invented customer values.
            $relevant=(bool)preg_match('/(?:elmetr|project|website|site|contact|lead|welcome|start|intro|افتتاح|ترحيب|مشروع|موقع)/iu',$name.' '.$blob);if(!$relevant)continue; // never auto-select an unrelated approved template.
            $score=20;if(strtolower($lang)===$want)$score+=50;$category=strtoupper((string)($t['category']??''));if($category==='MARKETING')$score+=8;if($category==='UTILITY')$score+=4;
            if(preg_match('/(?:elmetr|project|contact|lead|welcome|start|intro|افتتاح|ترحيب)/iu',$name))$score+=12;
            if($score>$bestScore){$bestScore=$score;$best=['name'=>$name,'language'=>$lang?:self::templateLanguage()];}
        }
        if(!$best)return false;
        try{put_setting('whatsapp.customer_template_name',$best['name']);put_setting('whatsapp.customer_template_language',$best['language']);put_setting('whatsapp.auto_selected_template_at',now_utc());return true;}catch(Throwable){return false;}
    }


    public static function queue(int $customerId,?int $conversationId,string $body,string $reason='outside_window'): int {
        $body=trim($body);if($customerId<1||$body==='')throw new RuntimeException('message_body_required');
        $hash=hash('sha256',preg_replace('/\s+/u',' ',$body)??$body);
        $q=db()->prepare("SELECT id FROM whatsapp_pending_messages WHERE customer_id=? AND body_hash=? AND state IN ('pending','waiting_reply','sending') ORDER BY id DESC LIMIT 1");
        $q->execute([$customerId,$hash]);$id=$q->fetchColumn();if($id)return (int)$id;
        $q=db()->prepare("INSERT INTO whatsapp_pending_messages(customer_id,conversation_id,body_text,body_hash,reason,state) VALUES (?,?,?,?,?,'pending')");
        $q->execute([$customerId,$conversationId?:null,$body,$hash,pb_substr($reason,0,80)]);
        return (int)db()->lastInsertId();
    }

    public static function waitingForReply(int $customerId): bool {
        $hours=self::windowHours();
        $q=db()->prepare("SELECT 1 FROM whatsapp_pending_messages WHERE customer_id=? AND state='waiting_reply' AND updated_at>=DATE_SUB(NOW(),INTERVAL ".$hours." HOUR) ORDER BY id DESC LIMIT 1");
        $q->execute([$customerId]);return (bool)$q->fetchColumn();
    }

    public static function pending(int $customerId,int $limit=3): array {
        $limit=max(1,min(10,$limit));
        $q=db()->prepare("SELECT * FROM whatsapp_pending_messages WHERE customer_id=? AND state IN ('pending','waiting_reply') ORDER BY id ASC LIMIT ".$limit);
        $q->execute([$customerId]);return $q->fetchAll();
    }

    public static function markWaiting(int $id,string $templateMessageId): void {
        db()->prepare("UPDATE whatsapp_pending_messages SET state='waiting_reply',template_message_id=?,attempts=attempts+1,last_error=NULL,updated_at=NOW() WHERE id=?")->execute([$templateMessageId?:null,$id]);
    }

    public static function markSending(int $id): void {
        db()->prepare("UPDATE whatsapp_pending_messages SET state='sending',attempts=attempts+1,updated_at=NOW() WHERE id=?")->execute([$id]);
    }

    public static function markSent(int $id): void {
        db()->prepare("UPDATE whatsapp_pending_messages SET state='sent',sent_at=NOW(),last_error=NULL,updated_at=NOW() WHERE id=?")->execute([$id]);
    }

    public static function markFailed(int $id,string $error): void {
        db()->prepare("UPDATE whatsapp_pending_messages SET state='failed',last_error=?,updated_at=NOW() WHERE id=?")->execute([pb_substr(Security::redactSecrets($error),0,190),$id]);
    }

    public static function markRetryPending(int $id,string $error,bool $clearTemplate=true,bool $resetAttempts=false): void {
        $safe=pb_substr(Security::redactSecrets($error),0,190);
        $attemptSql=$resetAttempts?'attempts=0,':"attempts=attempts,";
        $stateExpr=$resetAttempts?"'pending'":"IF(attempts>=3,'failed','pending')";
        $sql=$clearTemplate?"UPDATE whatsapp_pending_messages SET state=$stateExpr,$attemptSql template_message_id=NULL,last_error=?,updated_at=NOW() WHERE id=?":"UPDATE whatsapp_pending_messages SET state=$stateExpr,$attemptSql last_error=?,updated_at=NOW() WHERE id=?";
        db()->prepare($sql)->execute([$safe,$id]);
    }

    public static function recoverFailedQueue():int{
        try{
            $q=db()->prepare("UPDATE whatsapp_pending_messages SET state='pending',template_message_id=NULL,attempts=IF(last_error LIKE '%131058%' OR last_error LIKE '%Hello World%',0,attempts),updated_at=NOW() WHERE state='failed' AND sent_at IS NULL AND (attempts<3 OR last_error LIKE '%131058%' OR last_error LIKE '%Hello World%') AND (reason IN ('outside_24h_window','meta_131047') OR last_error LIKE '%131047%' OR last_error LIKE '%131058%' OR last_error LIKE '%Hello World%' OR last_error LIKE '%template%' OR last_error LIKE '%قالب%' OR last_error LIKE '%WhatsApp%')");
            $q->execute();return $q->rowCount();
        }catch(Throwable){return 0;}
    }

    public static function pendingCustomers(int $limit=8):array{
        $limit=max(1,min(30,$limit));$q=db()->query("SELECT customer_id,MIN(id) first_pending_id,COUNT(*) pending_count FROM whatsapp_pending_messages WHERE state='pending' AND sent_at IS NULL AND attempts<3 GROUP BY customer_id ORDER BY first_pending_id DESC LIMIT ".$limit);return $q->fetchAll();
    }

    public static function byTemplateMessageId(string $messageId): ?array {
        if($messageId==='')return null;
        $q=db()->prepare('SELECT * FROM whatsapp_pending_messages WHERE template_message_id=? ORDER BY id DESC LIMIT 1');$q->execute([$messageId]);
        return $q->fetch()?:null;
    }

    public static function metaFailure(array $status): array {
        $code=0;$title='';$details='';
        foreach((array)($status['raw']['errors']??[]) as $e){
            if(!is_array($e))continue;
            $code=(int)($e['code']??0);$title=trim((string)($e['title']??$e['message']??''));
            $details=trim((string)($e['error_data']['details']??$e['message']??''));break;
        }
        return ['code'=>$code,'title'=>$title,'details'=>$details];
    }

    public static function humanFailure(array $status): string {
        $e=self::metaFailure($status);$code=(int)$e['code'];
        if($code===131047)return 'Meta رفض الرسالة الحرة لأن نافذة خدمة العميل 24 ساعة مقفولة. لازم تبدأ بقالب WhatsApp معتمد، وبعد ما العميل يرد يقدر رامي يكمل برسائل عادية.';
        if($code===131058)return 'القالب hello_world قالب تجريبي من Meta ومسموح فقط مع Public Test Numbers. تم منعه في Company OS؛ استخدم قالب افتتاح مخصص Approved على رقم الشركة الحقيقي.';
        if($code===131026)return 'Meta لم يقدر يوصل الرسالة للرقم. راجع إن الرقم عليه WhatsApp وصيغته الدولية صحيحة، ثم أعد المحاولة.';
        if($code===131049)return 'Meta أوقف الرسالة بسبب حدود جودة/تفاعل WhatsApp. راجع حالة القالب وجودة الحساب قبل إعادة الإرسال.';
        if($code===132001)return 'Meta لم تجد قالب WhatsApp بالاسم/اللغة المحددين. راجع اسم القالب واللغة حرفيًا من WhatsApp Manager ثم أعد الاختبار.';
        if($code===132012)return 'Meta رفضت معاملات قالب WhatsApp لأن صيغة القالب أو المتغيرات لا تطابق القالب المعتمد.';
        if($code===131008)return 'رسالة القالب ناقصها باراميتر مطلوب حسب القالب المعتمد في Meta.';
        $detail=trim((string)$e['details']);
        return $detail!==''?'Meta رفض الرسالة: '.pb_substr($detail,0,260):'Meta رجّع حالة فشل للرسالة بدون سبب تفصيلي.';
    }
}
