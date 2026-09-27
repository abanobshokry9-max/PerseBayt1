<?php
declare(strict_types=1);

final class BrowserAutomationService {
    public static function configured(): bool {
        return trim((string)SecretVault::get('connections.browser_automation.url',''))!=='';
    }

    private static function endpoint(): string {
        $u=trim((string)SecretVault::get('connections.browser_automation.url',''));if($u==='')throw new RuntimeException('browser_automation_not_configured');return rtrim($u,'/');
    }

    private static function headers(): array {
        $token=trim((string)SecretVault::get('connections.browser_automation.token',''));return $token!==''?['Authorization'=>'Bearer '.$token]:[];
    }

    private static function mappedException(Throwable $e): RuntimeException {
        $m=trim(Security::redactSecrets($e->getMessage(),500));$l=strtolower($m);
        if(str_starts_with($l,'http_401'))return new RuntimeException('browser_automation_http_401:'.$m,0,$e);
        if(str_starts_with($l,'http_403'))return new RuntimeException('browser_automation_http_403:'.$m,0,$e);
        if(str_starts_with($l,'http_404'))return new RuntimeException('browser_automation_http_404:'.$m,0,$e);
        return $e instanceof RuntimeException?$e:new RuntimeException($m?:'browser_automation_error',0,$e);
    }

    public static function test(): array {
        try{$r=HttpClient::json('POST',self::endpoint().'/v1/test',self::headers(),['source'=>'persebayt','time'=>now_utc()],35);}
        catch(Throwable $e){throw self::mappedException($e);}
        return ['ok'=>(bool)($r['ok']??false),'runtime'=>$r['runtime']??null,'browser'=>$r['browser']??null];
    }

    public static function execute(int $agentId,int $accountId,string $operation,array $params=[],bool $provisioning=false): array {
        AgentService::requireTool($agentId,'browser_automation');Permissions::requireAgent($agentId,'browser.execute');
        $allowed=['navigate','login','snapshot','click','fill','submit','send_message','create_account','check_notifications','logout'];
        if(!in_array($operation,$allowed,true))throw new RuntimeException('browser_operation_not_allowed');
        $account=$accountId>0?($provisioning?ServiceAccountService::credentialForProvisioning($agentId,$accountId):ServiceAccountService::credentialForExecution($agentId,$accountId)):null;
        $payload=['operation'=>$operation,'account'=>$account,'params'=>$params,'limits'=>['max_steps'=>30,'max_seconds'=>90,'allow_downloads'=>false,'allow_captcha_bypass'=>false,'allow_2fa_bypass'=>false]];
        AgentUsageBudgetService::authorize($agentId,'browser');
        try{$r=HttpClient::json('POST',self::endpoint().'/v1/execute',self::headers(),$payload,110);AgentUsageBudgetService::record($agentId,'browser',1,0,['operation'=>$operation,'account_id'=>$accountId,'provisioning'=>$provisioning]);}catch(Throwable $e){try{AgentUsageBudgetService::record($agentId,'browser',1,0,['operation'=>$operation,'account_id'=>$accountId,'failed'=>true,'provisioning'=>$provisioning]);}catch(Throwable){}throw self::mappedException($e);}finally{if(isset($payload['account']['password']))$payload['account']['password']='***';}
        $challenge=(string)($r['challenge']??'');
        if($challenge!==''||!empty($r['requires_human']))self::challenge($agentId,$accountId,$challenge?:'human_verification',(string)($r['url']??''));
        $safe=$r;if(isset($safe['credentials']))unset($safe['credentials']);if(isset($safe['password']))$safe['password']='***';
        Audit::log('agent',(string)$agentId,'browser.execute','service_account',$accountId?(string)$accountId:null,!empty($r['ok'])?'verified':'failed',null,null,['operation'=>$operation,'provisioning'=>$provisioning,'result'=>json_decode(Security::redactSecrets(j($safe),6000),true)?:['redacted'=>true]]);
        return $safe;
    }

    private static function challenge(int $agentId,int $accountId,string $challenge,string $url): void {
        if($accountId>0)try{ServiceAccountService::setStatus($accountId,'challenge',['challenge'=>$challenge,'url'=>$url,'at'=>now_utc()]);}catch(Throwable){}
        $a=AgentService::byId($agentId);$msg=$a['display_name'].' توقف بسبب '.($challenge?:'تحقق بشري').($url!==''?' على '.$url:'').'. محتاج تدخل المالك لإكمال CAPTCHA/2FA ثم إعادة المهمة.';
        try{Notifications::add('warning','agents','مطلوب تدخل بشري في المتصفح',$msg,'agent',(string)$agentId);}catch(Throwable){}
        if(setting('browser.challenge_notify_whatsapp','1')==='1'){
            try{CommunicationGateway::agentToOwner((string)$a['slug'],$msg,'whatsapp',false,true);}catch(Throwable $e){error_log('ELMETR browser challenge owner notify: '.Security::redactSecrets($e->getMessage(),180));}
        }
    }
}
