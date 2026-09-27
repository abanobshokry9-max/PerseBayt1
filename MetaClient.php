<?php
declare(strict_types=1);

final class MetaClient {
    public static function configured():bool{
        return strlen((string)config('meta.access_token',''))>30
            && preg_match('/^[0-9]{5,30}$/',(string)config('meta.phone_number_id',''))===1;
    }

    public static function verifySignature(string $raw,string $header):bool{
        $secret=(string)config('meta.app_secret','');
        if(strlen($secret)<16||!str_starts_with($header,'sha256='))return false;
        return hash_equals('sha256='.hash_hmac('sha256',$raw,$secret),$header);
    }

    private static function endpoint():string{
        if(!self::configured())throw new RuntimeException('meta_not_configured');
        $v=(string)config('meta.graph_version','v23.0');
        $id=(string)config('meta.phone_number_id','');
        return 'https://graph.facebook.com/'.$v.'/'.$id.'/messages';
    }

    private static function sendPayload(array $payload):array{
        $headers=['Authorization'=>'Bearer '.(string)config('meta.access_token','')];
        try{
            $r=HttpClient::json('POST',self::endpoint(),$headers,$payload,35);
            if(empty($r['messages'][0]['id']))throw new RuntimeException('meta_send_no_message_id');
            return $r;
        }catch(Throwable $e){
            $m=pb_strtolower($e->getMessage());
            $retry=str_contains($m,'temporarily unavailable')||str_contains($m,'(#2)')||str_contains($m,'http_429')||preg_match('/http_5[0-9]{2}/',$m);
            if(!$retry)throw $e;
            usleep(350000);
            $r=HttpClient::json('POST',self::endpoint(),$headers,$payload,35);
            if(empty($r['messages'][0]['id']))throw new RuntimeException('meta_send_no_message_id');
            return $r;
        }
    }

    public static function sendText(string $to,string $body):array{
        $to=ConversationService::normalizePhone($to);$body=trim($body);
        if($to===''||strlen($to)>15)throw new RuntimeException('meta_invalid_recipient');
        if($body==='')throw new RuntimeException('meta_empty_message');
        $payload=[
            'messaging_product'=>'whatsapp',
            'recipient_type'=>'individual',
            'to'=>$to,
            'type'=>'text',
            'text'=>['preview_url'=>false,'body'=>pb_substr($body,0,4000)],
        ];
        return self::sendPayload($payload);
    }

    public static function sendTemplate(string $to,string $templateName,string $language='ar'):array{
        $to=ConversationService::normalizePhone($to);
        $templateName=trim($templateName);$language=trim($language);
        if($to===''||strlen($to)>15)throw new RuntimeException('meta_invalid_recipient');
        if(!preg_match('/^[a-z0-9_]{1,190}$/',$templateName))throw new RuntimeException('meta_template_name_invalid');
        if(WhatsAppPolicy::isReservedTemplate($templateName))throw new RuntimeException('meta_sample_template_not_allowed:'.$templateName);
        if(!preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z]{2})?$/',$language))throw new RuntimeException('meta_template_language_invalid');
        $payload=[
            'messaging_product'=>'whatsapp',
            'recipient_type'=>'individual',
            'to'=>$to,
            'type'=>'template',
            'template'=>['name'=>$templateName,'language'=>['code'=>$language]],
        ];
        return self::sendPayload($payload);
    }

    public static function templates(?string $status=null):array{
        if(!self::configured())throw new RuntimeException('meta_not_configured');
        $waba=trim((string)setting('meta.waba_id',''));if(!preg_match('/^[0-9]{5,30}$/',$waba))throw new RuntimeException('meta_waba_id_missing');
        $v=(string)config('meta.graph_version','v23.0');$headers=['Authorization'=>'Bearer '.(string)config('meta.access_token','')];$out=[];$after='';$wanted=$status!==null?strtoupper(trim($status)):'';
        for($page=0;$page<5;$page++){
            $q=['fields'=>'name,status,language,category,components','limit'=>100];if($wanted!=='')$q['status']=$wanted;if($after!=='')$q['after']=$after;
            $r=HttpClient::json('GET','https://graph.facebook.com/'.$v.'/'.$waba.'/message_templates?'.http_build_query($q,'','&',PHP_QUERY_RFC3986),$headers,null,30);
            foreach((array)($r['data']??[]) as $t){if(!is_array($t))continue;if($wanted!==''&&strtoupper((string)($t['status']??''))!==$wanted)continue;$out[]=$t;}
            $after=trim((string)($r['paging']['cursors']['after']??''));if($after==='')break;
        }
        return $out;
    }

    public static function approvedTemplates():array{return self::templates('APPROVED');}

    private static function classifyTemplateError(string $raw):string{
        $low=pb_strtolower($raw);
        if(str_contains($low,'whatsapp_business_management')||str_contains($low,'permission')||str_contains($low,'permissions error')||str_contains($low,'(#200)'))return 'meta_template_management_permission_missing';
        if(str_contains($low,'meta_waba_id_missing'))return 'meta_waba_id_missing';
        if(str_contains($low,'http_401')||str_contains($low,'invalid oauth')||str_contains($low,'access token'))return 'meta_access_token_invalid';
        return 'meta_template_management_probe_failed';
    }

    public static function templateManagementProbe():array{
        $waba=self::recoverWabaIdFromRecentWebhook();
        if($waba==='')return ['ok'=>false,'code'=>'meta_waba_id_missing','waba_id'=>null,'approved_count'=>0,'error'=>'meta_waba_id_missing'];
        try{
            $rows=self::approvedTemplates();
            return ['ok'=>true,'code'=>'ok','waba_id'=>$waba,'approved_count'=>count($rows),'templates'=>$rows,'error'=>null];
        }catch(Throwable $e){
            $raw=pb_substr(Security::redactSecrets($e->getMessage(),300),0,300);
            return ['ok'=>false,'code'=>self::classifyTemplateError($raw),'waba_id'=>$waba,'approved_count'=>0,'error'=>$raw];
        }
    }

    public static function customerOutboundReadiness():array{
        $out=['messaging_ok'=>false,'phone'=>null,'verified_name'=>null,'waba_id'=>null,'template_management_ok'=>false,'template_management_code'=>null,'approved_count'=>0,'configured_template'=>WhatsAppPolicy::templateName()?:null,'configured_language'=>WhatsAppPolicy::templateLanguage(),'configured_template_approved'=>false,'template_verified_by_delivery'=>false,'ready'=>false,'reason'=>''];
        try{$phone=self::test();$out['messaging_ok']=true;$out['phone']=$phone['display_phone_number']??null;$out['verified_name']=$phone['verified_name']??null;}catch(Throwable $e){$out['reason']=pb_substr(Security::redactSecrets($e->getMessage(),220),0,220);return $out;}
        $probe=self::templateManagementProbe();$out['waba_id']=$probe['waba_id']??null;$out['template_management_ok']=!empty($probe['ok']);$out['template_management_code']=$probe['code']??null;$out['approved_count']=(int)($probe['approved_count']??0);
        $name=(string)($out['configured_template']??'');$lang=(string)$out['configured_language'];
        if(!empty($probe['ok'])&&$name!=='')foreach((array)($probe['templates']??[]) as $t){if((string)($t['name']??'')===$name&&(string)($t['language']??'')===$lang){$out['configured_template_approved']=true;break;}}
        $verifiedName=(string)setting('whatsapp.template_verified_name','');$verifiedLang=(string)setting('whatsapp.template_verified_language','');$verifiedAt=(string)setting('whatsapp.template_verified_delivery_at','');
        $out['template_verified_by_delivery']=$name!==''&&$verifiedAt!==''&&hash_equals($name,$verifiedName)&&hash_equals($lang,$verifiedLang);
        $out['ready']=$out['messaging_ok']&&$name!==''&&($out['configured_template_approved']||$out['template_verified_by_delivery']);
        if(!$out['messaging_ok'])$out['reason']='meta_messaging_unavailable';
        elseif($name==='')$out['reason']=$out['template_management_ok']?'meta_no_approved_template':($out['template_management_code']?:'meta_template_management_probe_failed');
        elseif($out['template_management_ok']&&!$out['configured_template_approved']&&!$out['template_verified_by_delivery'])$out['reason']='meta_configured_template_not_approved';
        elseif(!$out['template_management_ok']&&!$out['template_verified_by_delivery'])$out['reason']='meta_template_unverified_send_required';
        else $out['reason']='ready';
        return $out;
    }

    public static function ensureOpeningTemplate():array{
        $waba=self::recoverWabaIdFromRecentWebhook();if($waba==='')throw new RuntimeException('meta_waba_id_missing');
        $base='elmetr_project_contact';$language='ar';$all=self::templates();$candidate=null;$maxVersion=0;
        foreach($all as $t){$name=trim((string)($t['name']??''));if(!preg_match('/^'.preg_quote($base,'/').'(?:_v([0-9]+))?$/',$name,$m))continue;$v=isset($m[1])?(int)$m[1]:1;$maxVersion=max($maxVersion,$v);$state=strtoupper((string)($t['status']??''));if($state==='APPROVED'){$candidate=$t;break;}if($candidate===null||$state==='PENDING')$candidate=$t;}
        if($candidate){$state=strtoupper((string)($candidate['status']??'UNKNOWN'));if($state==='APPROVED'){put_setting('whatsapp.customer_template_name',(string)$candidate['name']);put_setting('whatsapp.customer_template_language',(string)($candidate['language']?:$language));put_setting('whatsapp.template_submission_state','APPROVED');return ['created'=>false,'state'=>'APPROVED','name'=>$candidate['name'],'language'=>$candidate['language']?:$language];}if($state==='PENDING'){put_setting('whatsapp.template_submission_state','PENDING');return ['created'=>false,'state'=>'PENDING','name'=>$candidate['name'],'language'=>$candidate['language']?:$language];}}
        if(setting('whatsapp.auto_create_template','1')==='0')return ['created'=>false,'state'=>'MISSING','name'=>'','language'=>$language];
        $name=$base.'_v'.max(1,$maxVersion+1);$payload=['name'=>$name,'language'=>$language,'category'=>'MARKETING','components'=>[['type'=>'BODY','text'=>'مرحبًا، معاك رامي من شركة المتر. بنتواصل بخصوص طلب إنشاء أو تطوير موقع. لو مناسب لك نكمل هنا لمعرفة التفاصيل وتجهيز عرض مناسب.']]];
        $v=(string)config('meta.graph_version','v23.0');$headers=['Authorization'=>'Bearer '.(string)config('meta.access_token','')];$r=HttpClient::json('POST','https://graph.facebook.com/'.$v.'/'.$waba.'/message_templates',$headers,$payload,35);$state=strtoupper((string)($r['status']??'PENDING'));put_setting('whatsapp.template_submission_state',$state);put_setting('whatsapp.template_submission_name',$name);put_setting('whatsapp.template_submission_at',now_utc());
        if($state==='APPROVED'){put_setting('whatsapp.customer_template_name',$name);put_setting('whatsapp.customer_template_language',$language);}
        return ['created'=>true,'state'=>$state,'name'=>$name,'language'=>$language,'id'=>$r['id']??null];
    }

    public static function rememberWabaId(array $payload):void{
        foreach((array)($payload['entry']??[]) as $e){$id=preg_replace('/\D+/','',(string)($e['id']??''));if($id!==''&&strlen($id)>=5&&strlen($id)<=30){try{put_setting('meta.waba_id',$id);}catch(Throwable){}return;}}
    }

    public static function recoverWabaIdFromRecentWebhook():string{
        $current=preg_replace('/\D+/','',(string)setting('meta.waba_id',''));if($current!==''&&strlen($current)>=5&&strlen($current)<=30)return $current;
        try{$rows=db()->query("SELECT payload_json FROM webhook_events WHERE provider='meta' ORDER BY id DESC LIMIT 40")->fetchAll(PDO::FETCH_COLUMN);}catch(Throwable){return '';}
        foreach($rows as $raw){try{$payload=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);}catch(Throwable){continue;}if(!is_array($payload))continue;self::rememberWabaId($payload);$current=preg_replace('/\D+/','',(string)setting('meta.waba_id',''));if($current!==''&&strlen($current)>=5&&strlen($current)<=30)return $current;}
        return '';
    }

    public static function test():array{
        if(!self::configured())throw new RuntimeException('meta_not_configured');
        $v=(string)config('meta.graph_version','v23.0');$id=(string)config('meta.phone_number_id','');
        $r=HttpClient::json('GET','https://graph.facebook.com/'.$v.'/'.$id.'?fields=id,display_phone_number,verified_name',['Authorization'=>'Bearer '.(string)config('meta.access_token','')],null,25);
        if((string)($r['id']??'')!==$id)throw new RuntimeException('meta_phone_id_mismatch');
        return ['id'=>$id,'display_phone_number'=>$r['display_phone_number']??null,'verified_name'=>$r['verified_name']??null];
    }

    public static function extractMessages(array $payload):array{
        $out=[];
        foreach(($payload['entry']??[]) as $e)foreach(($e['changes']??[]) as $ch){
            $v=$ch['value']??[];$phone=(string)($v['metadata']['phone_number_id']??'');
            if($phone!==''&&$phone!==(string)config('meta.phone_number_id',''))continue;
            $names=[];
            foreach((array)($v['contacts']??[]) as $contact){
                $wa=preg_replace('/\D+/','',(string)($contact['wa_id']??''));
                $name=trim((string)($contact['profile']['name']??''));
                if($wa!==''&&$name!=='')$names[$wa]=pb_substr($name,0,190);
            }
            foreach(($v['messages']??[]) as $m){
                $type=(string)($m['type']??'');$text='';
                if($type==='text')$text=(string)($m['text']['body']??'');
                elseif($type==='button')$text=(string)($m['button']['text']??'');
                elseif($type==='interactive')$text=(string)($m['interactive']['button_reply']['title']??$m['interactive']['list_reply']['title']??'');
                elseif($type==='image')$text='[صورة من WhatsApp] '.trim((string)($m['image']['caption']??''));
                elseif($type==='audio')$text='[رسالة صوتية من WhatsApp — media_id: '.(string)($m['audio']['id']??'').']';
                elseif($type==='video')$text='[فيديو من WhatsApp] '.trim((string)($m['video']['caption']??''));
                elseif($type==='document')$text='[مستند من WhatsApp: '.trim((string)($m['document']['filename']??'بدون اسم')).'] '.trim((string)($m['document']['caption']??''));
                elseif($type==='location')$text='[موقع من WhatsApp: '.(string)($m['location']['latitude']??'').','.(string)($m['location']['longitude']??'').']';
                else $text='[رسالة WhatsApp من نوع '.$type.']';
                $from=ConversationService::normalizePhone((string)($m['from']??''));if($from==='')continue;
                $out[]=[
                    'id'=>(string)($m['id']??''),'from'=>$from,'contact_name'=>$names[$from]??'',
                    'type'=>$type?:'text','text'=>trim($text),'raw'=>$m,'timestamp'=>(int)($m['timestamp']??time())
                ];
            }
        }
        return $out;
    }

    public static function extractStatuses(array $payload):array{
        $out=[];
        foreach(($payload['entry']??[]) as $e)foreach(($e['changes']??[]) as $ch){
            $v=$ch['value']??[];
            foreach(($v['statuses']??[]) as $st)$out[]=['id'=>(string)($st['id']??''),'status'=>(string)($st['status']??''),'timestamp'=>(int)($st['timestamp']??time()),'raw'=>$st];
        }
        return $out;
    }

    public static function extractCalls(array $payload):array{
        $out=[];
        foreach(($payload['entry']??[]) as $e)foreach(($e['changes']??[]) as $ch){
            $v=$ch['value']??[];foreach(($v['calls']??[]) as $c)if(is_array($c))$out[]=$c;
        }
        return $out;
    }
}
