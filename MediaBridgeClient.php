<?php
declare(strict_types=1);

/**
 * Guarded media bridge with an async/idempotent lifecycle.
 * A request is submitted once, then completed by webhook or status polling.
 */
final class MediaBridgeClient {
    private const TYPES=['image','video','ui_asset','audio'];
    private const PENDING_STATES=['requested','submitted','processing','generating'];

    private static function url():string{return trim((string)config('connections.media.url',''));}
    private static function token():string{return trim((string)config('connections.media.token',''));}
    private static function callbackUrl():string{return rtrim((string)config('app.base_url'),' /').'/webhooks/media.php';}
    public static function configured():bool{return (bool)filter_var(self::url(),FILTER_VALIDATE_URL)&&self::token()!=='';}

    public static function test():array{
        if(!self::configured())throw new RuntimeException('media_bridge_not_configured');
        $r=HttpClient::json('POST',Security::publicUrl(self::url()),['Authorization'=>'Bearer '.self::token(),'X-Elmetr-Bridge'=>'1'],['event'=>'media.test','source'=>'persebayt','time'=>now_utc()],35);
        if(isset($r['ok'])&&!$r['ok'])throw new RuntimeException('media_bridge_test_failed');
        return ['ok'=>true,'provider'=>$r['provider']??null,'capabilities'=>$r['capabilities']??null,'raw'=>$r];
    }

    private static function normalizedType(string $type):string{
        $type=pb_strtolower(trim($type));return in_array($type,self::TYPES,true)?$type:'image';
    }
    private static function budgetCapability(string $type,string $operation='generate'):string{
        if($operation==='compose')return 'video';
        return match($type){'video'=>'video','audio'=>'audio','image','ui_asset'=>'image',default=>'media'};
    }
    private static function estimatedCost(string $capability):float{
        $key='budget.'.$capability.'.estimated_request_usd';$fallback=$capability==='video'?'0.50':($capability==='audio'?'0.03':'0.05');return max(0,(float)setting($key,$fallback));
    }
    private static function idempotencyKey(int $projectId,int $taskId,int $agentId,string $type,string $label,string $prompt,array $options=[]):string{
        if(!empty($options['idempotency_key']))return hash('sha256',(string)$options['idempotency_key']);
        $stable=['project'=>$projectId,'task'=>$taskId,'agent'=>$agentId,'type'=>$type,'label'=>trim($label),'prompt'=>trim($prompt),'operation'=>(string)($options['operation']??'generate'),'source_ref'=>(string)($options['source_ref']??'')];
        return hash('sha256',j($stable));
    }
    private static function findByKey(string $key):?array{$q=db()->prepare('SELECT * FROM media_requests WHERE idempotency_key=? LIMIT 1');$q->execute([$key]);$r=$q->fetch();return $r?:null;}
    public static function get(int $id):array{$q=db()->prepare('SELECT * FROM media_requests WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('media_request_not_found');return $r;}

    private static function providerState(array $r):string{
        $s=pb_strtolower(trim((string)($r['state']??$r['status']??$r['job_state']??'')));
        if(in_array($s,['complete','completed','done','succeeded','success','ready','finished'],true))return 'completed';
        if(in_array($s,['failed','error','cancelled','canceled','rejected'],true))return 'failed';
        if(in_array($s,['queued','submitted','pending','accepted'],true))return 'submitted';
        if(in_array($s,['processing','running','generating','rendering','in_progress'],true))return 'processing';
        $hasRef=trim((string)($r['url']??$r['asset_url']??$r['output_url']??$r['path']??$r['file_path']??''))!=='';
        return $hasRef?'completed':'processing';
    }
    private static function providerJobId(array $r):string{return pb_substr(trim((string)($r['provider_job_id']??$r['job_id']??$r['generation_id']??$r['render_id']??$r['id']??'')),0,220);}

    private static function submit(array $row,string $prompt,array $options):array{
        if(!self::configured())return ['request_id'=>(int)$row['id'],'state'=>(string)$row['state'],'provider'=>null];
        $operation=(string)($options['operation']??'generate');$type=(string)$row['media_type'];$payload=[
            'event'=>$operation==='compose'?'media.compose':'media.generate',
            'type'=>$type,'prompt'=>pb_substr($prompt,0,12000),'label'=>pb_substr((string)$row['label'],0,220),
            'project_id'=>(int)$row['project_id'],'task_id'=>$row['task_id']?(int)$row['task_id']:null,'agent_id'=>$row['requested_by_agent_id']?(int)$row['requested_by_agent_id']:null,
            'request_id'=>(int)$row['id'],'idempotency_key'=>(string)$row['idempotency_key'],'callback_url'=>self::callbackUrl(),
        ];
        if($operation==='compose')$payload['manifest']=$options['manifest']??[];
        if(!empty($options['provider_options'])&&is_array($options['provider_options']))$payload['options']=$options['provider_options'];
        $r=HttpClient::json('POST',Security::publicUrl(self::url()),['Authorization'=>'Bearer '.self::token(),'X-Elmetr-Bridge'=>'1'],$payload,$operation==='compose'?120:90);
        return self::applyProviderResponse((int)$row['id'],$r,false);
    }

    public static function request(int $projectId,int $taskId,int $agentId,string $type,string $label,string $prompt,array $options=[]):array{
        $type=self::normalizedType($type);$label=trim($label)?:'ملف مرئي';$prompt=trim($prompt);if($prompt===''&&($options['operation']??'generate')!=='compose')throw new RuntimeException('media_prompt_required');
        $key=self::idempotencyKey($projectId,$taskId,$agentId,$type,$label,$prompt,$options);$existing=self::findByKey($key);
        if($existing){
            if((string)$existing['state']==='completed')return ['request_id'=>(int)$existing['id'],'state'=>'completed','provider'=>(string)($existing['provider']??''),'asset_id'=>(int)($existing['result_asset_id']??0)?:null,'provider_job_id'=>$existing['provider_job_id']??null,'reused'=>true];
            if(in_array((string)$existing['state'],self::PENDING_STATES,true))return ['request_id'=>(int)$existing['id'],'state'=>(string)$existing['state'],'provider'=>(string)($existing['provider']??''),'provider_job_id'=>$existing['provider_job_id']??null,'reused'=>true];
            // A failed request is only re-submitted if explicitly requested; otherwise keep the failure visible.
            if(empty($options['retry_failed']))return ['request_id'=>(int)$existing['id'],'state'=>'failed','provider'=>(string)($existing['provider']??''),'error'=>$existing['error_code']??'media_failed','reused'=>true];
            db()->prepare("UPDATE media_requests SET state='requested',error_code=NULL,provider_job_id=NULL,next_poll_at=NULL,last_checked_at=NULL,provider_state=NULL,completed_at=NULL WHERE id=?")->execute([(int)$existing['id']]);$existing=self::get((int)$existing['id']);
        }
        $provider=self::configured()?'media_bridge':null;$cap=self::budgetCapability($type,(string)($options['operation']??'generate'));$reservation=0;
        if(!$existing&&$provider!==null&&$agentId>0)$reservation=AiBudgetService::reserve($agentId,$cap,self::estimatedCost($cap),'media:'.$key,180);
        if(!$existing){
            $meta=['operation'=>(string)($options['operation']??'generate'),'budget_capability'=>$cap,'budget_reservation_id'=>$reservation?:null,'source_ref'=>$options['source_ref']??null];
            db()->prepare("INSERT INTO media_requests(project_id,task_id,requested_by_agent_id,media_type,label,prompt_text,provider,provider_job_id,idempotency_key,state,metadata_json) VALUES (?,?,?,?,?,?,?,NULL,?,'requested',?)")->execute([$projectId,$taskId?:null,$agentId?:null,$type,$label,$prompt,$provider,$key,j($meta)]);$rid=(int)db()->lastInsertId();$existing=self::get($rid);
        }
        if(!self::configured()){
            Notifications::add('info','media','طلب وسائط ينتظر خدمة التنفيذ',$label.' — اربط Media Bridge ليبدأ التنفيذ.','media_request',(string)$existing['id']);
            return ['request_id'=>(int)$existing['id'],'state'=>'requested','provider'=>null,'idempotency_key'=>$key];
        }
        try{
            if($agentId>0)AgentUsageBudgetService::authorize($agentId,'media');
            $result=self::submit($existing,$prompt,$options)+['idempotency_key'=>$key];
            if($agentId>0)AgentUsageBudgetService::record($agentId,'media',1,0,['request_id'=>(int)$existing['id'],'media_type'=>$type,'operation'=>(string)($options['operation']??'generate')]);
            return $result;
        }
        catch(Throwable $e){
            if($agentId>0)try{AgentUsageBudgetService::record($agentId,'media',1,0,['request_id'=>(int)$existing['id'],'media_type'=>$type,'failed'=>true]);}catch(Throwable){}
            $safe=pb_substr(Security::redactSecrets($e->getMessage()),0,160);$meta=json_decode((string)($existing['metadata_json']??'{}'),true)?:[];if(!empty($meta['budget_reservation_id']))AiBudgetService::releaseReservation((int)$meta['budget_reservation_id']);
            db()->prepare("UPDATE media_requests SET state='failed',error_code=?,last_checked_at=NOW() WHERE id=?")->execute([$safe,(int)$existing['id']]);Notifications::add('warning','media','فشل تقديم طلب الوسائط',$label.' — '.AdminUi::humanError($safe),'media_request',(string)$existing['id']);
            return ['request_id'=>(int)$existing['id'],'state'=>'failed','provider'=>'media_bridge','error'=>$safe,'idempotency_key'=>$key];
        }
    }

    public static function compose(int $projectId,int $taskId,int $agentId,string $label,array $manifest,?int $renderJobId=null):array{
        if(!$manifest)throw new RuntimeException('media_compose_manifest_empty');$source='render:'.($renderJobId?:hash('sha256',j($manifest)));
        return self::request($projectId,$taskId,$agentId,'video',$label,'compose-manifest:'.hash('sha256',j($manifest)),['operation'=>'compose','manifest'=>$manifest,'source_ref'=>$source,'idempotency_key'=>$source]);
    }

    public static function applyProviderResponse(int $requestId,array $r,bool $fromWebhook=true):array{
        $row=self::get($requestId);$state=self::providerState($r);$providerJob=self::providerJobId($r);$providerState=pb_substr((string)($r['state']??$r['status']??''),0,120);
        if($state==='completed')return self::complete($row,$r,$providerJob);
        if($state==='failed')return self::fail($row,(string)($r['error']??$r['message']??'media_failed'),$r,$providerJob);
        $poll=max(20,min(600,(int)setting('video.media_poll_interval_seconds','60')));db()->prepare("UPDATE media_requests SET state=?,provider_job_id=COALESCE(NULLIF(?,''),provider_job_id),provider_state=?,last_checked_at=NOW(),next_poll_at=DATE_ADD(NOW(),INTERVAL ? SECOND),error_code=NULL WHERE id=?")->execute([$state,$providerJob,$providerState?:$state,$poll,$requestId]);
        return ['request_id'=>$requestId,'state'=>$state,'provider'=>'media_bridge','provider_job_id'=>$providerJob?:($row['provider_job_id']??null),'pending'=>true];
    }

    private static function complete(array $row,array $payload,string $providerJob=''):array{
        $id=(int)$row['id'];if((string)$row['state']==='completed'&&!empty($row['result_asset_id']))return ['request_id'=>$id,'state'=>'completed','asset_id'=>(int)$row['result_asset_id'],'provider_job_id'=>$row['provider_job_id']??null,'reused'=>true];
        $url=trim((string)($payload['url']??$payload['asset_url']??$payload['output_url']??''));if($url!=='')$url=Security::publicUrl($url);$path=trim((string)($payload['path']??$payload['file_path']??''));if($url===''&&$path==='')throw new RuntimeException('media_result_reference_missing');
        $assetType=(string)$row['media_type'];if(!in_array($assetType,['image','video','ui_asset','audio'],true))$assetType='file';
        db()->prepare("INSERT INTO project_assets(project_id,asset_type,label,source,path_ref,url_ref,status,metadata_json) VALUES (?,?,?,'generated',?,?,'active',?)")->execute([(int)$row['project_id'],$assetType,(string)$row['label'],$path?:null,$url?:null,j(['media_request_id'=>$id,'provider_response'=>$payload])]);$asset=(int)db()->lastInsertId();
        db()->prepare("UPDATE media_requests SET state='completed',provider_job_id=COALESCE(NULLIF(?,''),provider_job_id),provider_state='completed',result_asset_id=?,error_code=NULL,next_poll_at=NULL,last_checked_at=NOW(),completed_at=NOW() WHERE id=?")->execute([$providerJob,$asset,$id]);
        $meta=json_decode((string)($row['metadata_json']??'{}'),true)?:[];$agentId=(int)($row['requested_by_agent_id']??0);if($agentId>0){try{$cap=(string)($meta['budget_capability']??self::budgetCapability((string)$row['media_type'],(string)($meta['operation']??'generate')));AiBudgetService::record($agentId,(string)($payload['provider']??'media_bridge'),(string)($payload['model']??$payload['provider']??$row['media_type']),$cap,0,0,['operation'=>$meta['operation']??'generate','media_type'=>$row['media_type'],'request_id'=>$id,'asset_id'=>$asset,'reservation_id'=>(int)($meta['budget_reservation_id']??0)],$row['task_id']?(int)$row['task_id']:null,(int)$row['project_id']);}catch(Throwable $e){error_log('ELMETR media budget ledger: '.Security::redactSecrets($e->getMessage(),180));}}
        try{VideoStudioService::mediaCompleted($id,$asset,$payload);}catch(Throwable $e){error_log('ELMETR media completion hook: '.Security::redactSecrets($e->getMessage(),180));}
        Audit::log('system','media_bridge','media.generated','project_asset',(string)$asset,'verified',(int)$row['project_id'],$row['task_id']?(int)$row['task_id']:null,['request_id'=>$id,'type'=>$row['media_type']]);
        return ['request_id'=>$id,'state'=>'completed','provider'=>'media_bridge','asset_id'=>$asset,'url'=>$url?:null,'path'=>$path?:null,'provider_job_id'=>$providerJob?:($row['provider_job_id']??null)];
    }

    private static function fail(array $row,string $error,array $payload=[],string $providerJob=''):array{
        $safe=pb_substr(Security::redactSecrets($error),0,180);db()->prepare("UPDATE media_requests SET state='failed',provider_job_id=COALESCE(NULLIF(?,''),provider_job_id),provider_state='failed',error_code=?,next_poll_at=NULL,last_checked_at=NOW(),completed_at=NOW() WHERE id=?")->execute([$providerJob,$safe,(int)$row['id']]);$meta=json_decode((string)($row['metadata_json']??'{}'),true)?:[];if(!empty($meta['budget_reservation_id']))AiBudgetService::releaseReservation((int)$meta['budget_reservation_id']);try{VideoStudioService::mediaFailed((int)$row['id'],$safe,$payload);}catch(Throwable){}
        return ['request_id'=>(int)$row['id'],'state'=>'failed','error'=>$safe,'provider_job_id'=>$providerJob?:($row['provider_job_id']??null)];
    }

    public static function pollPending(int $limit=20):array{
        if(!self::configured())return ['checked'=>0,'completed'=>0,'failed'=>0,'pending'=>0,'configured'=>false];$limit=max(1,min(100,$limit));$q=db()->query("SELECT id,provider_job_id FROM media_requests WHERE state IN ('submitted','processing','generating') AND provider_job_id IS NOT NULL AND provider_job_id<>'' AND (next_poll_at IS NULL OR next_poll_at<=NOW()) ORDER BY COALESCE(next_poll_at,created_at),id LIMIT ".$limit);$checked=$completed=$failed=$pending=0;
        foreach($q->fetchAll() as $r){$checked++;try{$resp=HttpClient::json('POST',Security::publicUrl(self::url()),['Authorization'=>'Bearer '.self::token(),'X-Elmetr-Bridge'=>'1'],['event'=>'media.status','request_id'=>(int)$r['id'],'provider_job_id'=>(string)$r['provider_job_id']],45);$out=self::applyProviderResponse((int)$r['id'],$resp,false);if($out['state']==='completed')$completed++;elseif($out['state']==='failed')$failed++;else $pending++;}catch(Throwable $e){$pending++;$delay=max(60,min(900,(int)setting('video.media_poll_interval_seconds','60')*2));db()->prepare("UPDATE media_requests SET last_checked_at=NOW(),next_poll_at=DATE_ADD(NOW(),INTERVAL ? SECOND),error_code=? WHERE id=?")->execute([$delay,pb_substr(Security::redactSecrets($e->getMessage()),0,160),(int)$r['id']]);}}
        return compact('checked','completed','failed','pending')+['configured'=>true];
    }
}
