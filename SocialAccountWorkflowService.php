<?php
declare(strict_types=1);

/** Unified guarded flow: account record -> Vault -> approved playbook -> challenge/active. */
final class SocialAccountWorkflowService {
    private const SIGNUP=[
        'gmail'=>'https://accounts.google.com/signup',
        'google'=>'https://accounts.google.com/signup',
        'facebook'=>'https://www.facebook.com/r.php',
        'instagram'=>'https://www.instagram.com/accounts/emailsignup/',
        'x'=>'https://x.com/i/flow/signup',
        'twitter'=>'https://x.com/i/flow/signup',
        'tiktok'=>'https://www.tiktok.com/signup',
        'youtube'=>'https://accounts.google.com/signup',
        'telegram'=>'https://web.telegram.org/',
    ];

    /** Seed only the missing built-in Gmail provisioning playbook. Existing owner playbooks are never overwritten. */
    public static function ensureBuiltins():void{
        try{$q=db()->prepare("SELECT id FROM social_playbooks WHERE platform='gmail' AND operation_key='create_account' AND version_no=1 LIMIT 1");$q->execute();$id=(int)($q->fetchColumn()?:0);if(!$id){db()->prepare("INSERT INTO social_playbooks(platform,operation_key,version_no,label_ar,state,owner_approved,config_json) VALUES ('gmail','create_account',1,'Gmail / Google — إنشاء حساب','approved',1,?)")->execute([j(['signup_url'=>'https://accounts.google.com/signup','builtin'=>'companyos_20_1_4'])]);$id=(int)db()->lastInsertId();}
            $q=db()->prepare('SELECT COUNT(*) FROM social_playbook_steps WHERE playbook_id=?');$q->execute([$id]);if((int)$q->fetchColumn()===0){db()->prepare("INSERT INTO social_playbook_steps(playbook_id,step_no,action_type,selector_text,value_template,expected_text,challenge_policy,evidence_policy) VALUES (?,1,'navigate','',?,'','stop_and_notify','screenshot_and_url'),(?,2,'create_account','',?,'','stop_and_notify','screenshot_and_url')")->execute([$id,'https://accounts.google.com/signup',$id,j(['fields'=>[],'provider_hint'=>'google_account'])]);}}
        catch(Throwable $e){throw new RuntimeException('gmail_playbook_seed_failed:'.Security::redactSecrets($e->getMessage(),180),0,$e);}
    }

    public static function request(int $agentId,array $d,int $taskId=0):array{
        Permissions::requireAgent($agentId,'accounts.create');Permissions::requireAgent($agentId,'browser.execute');$platform=pb_strtolower(trim((string)($d['platform']??'')));if($platform==='twitter')$platform='x';if($platform==='google')$platform='gmail';if($platform==='')throw new RuntimeException('service_account_platform_invalid');$d['platform']=$platform;$d['login_url']=$d['login_url']??(self::SIGNUP[$platform]??'');$d['display_name']=trim((string)($d['display_name']??''))?:ucfirst($platform).' account';$d['purpose']=trim((string)($d['purpose']??''))?:'حساب تشغيلي طلبه النظام/المدير.';$id=ServiceAccountService::createGenerated($agentId,$d);ServiceAccountService::setStatus($id,'pending',['workflow'=>'create_account','requested_at'=>now_utc(),'task_id'=>$taskId?:null]);
        $p=SocialPlaybookService::findApproved($platform,'create_account');if(!$p){$p=SocialPlaybookService::findApproved($platform,'signup');}
        if(!$p){Notifications::add('warning','agents','الحساب محفوظ وينتظر Playbook معتمد','تم إنشاء بيانات الحساب وحفظ كلمة المرور في Vault، لكن لا يوجد Playbook create_account معتمد لمنصة '.$platform.'. لم يتم تسجيل إنشاء الحساب الخارجي كنجاح.','service_account',(string)$id);throw new RuntimeException('social_playbook_missing:'.$platform);}
        try{$run=SocialPlaybookService::start($agentId,$id,(int)$p['id'],$taskId,['purpose'=>(string)$d['purpose'],'display_name'=>(string)$d['display_name']]);return ['account_id'=>$id,'state'=>'queued','run_id'=>$run['run_id'],'playbook_id'=>(int)$p['id'],'platform'=>$platform];}
        catch(Throwable $e){ServiceAccountService::setStatus($id,'error',['workflow'=>'create_account','error'=>pb_substr(Security::redactSecrets($e->getMessage()),0,300)]);throw $e;}
    }
}
