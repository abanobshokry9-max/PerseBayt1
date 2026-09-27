<?php
declare(strict_types=1);

/**
 * إدارة محطة الاختبار العامة للمشاريع قبل التسليم.
 * لا يتم إرسال أسرار للموديلات، وكل عمليات الملفات تمر عبر HostingerClient
 * لكي تستفيد من النسخ الاحتياطية وسجل الأدلة الموجودين أصلًا.
 */
final class StagingService {
    public static function enabled():bool{return setting('staging.enabled','1')==='1';}
    public static function rootDomain():string{
        $raw=trim((string)setting('staging.domain','nourmakkah.com'));
        return $raw!==''?Security::domain($raw):'nourmakkah.com';
    }
    public static function eligible(array $project):bool{
        if(!self::enabled())return false;
        if((int)($project['is_system_project']??0)===1){
            $domain=pb_strtolower(trim((string)($project['primary_domain']??'')));
            $appHost=pb_strtolower((string)(parse_url((string)config('app.base_url','https://persebayt.com'),PHP_URL_HOST)?:'persebayt.com'));
            // The Company OS itself may use the lab before an owner-approved self-upgrade.
            // The lab host (nourmakkah.com) must never stage itself recursively.
            return $domain!==''&&$domain===$appHost&&strcasecmp($domain,self::rootDomain())!==0;
        }
        return (string)($project['source']??'')==='client_project'||(int)($project['opportunity_id']??0)>0;
    }
    public static function metadata(array $project):array{
        $meta=json_decode((string)($project['metadata_json']??'{}'),true);return is_array($meta)?$meta:[];
    }
    public static function existing(array $project):?array{
        $meta=self::metadata($project);$s=$meta['staging']??null;if(!is_array($s))return null;
        $domain=trim((string)($s['domain']??''));$sub=trim((string)($s['subdomain']??''));$dir=trim((string)($s['directory']??''));
        if($domain===''||$sub===''||$dir==='')return null;
        return ['domain'=>$domain,'subdomain'=>$sub,'directory'=>$dir,'state'=>(string)($s['state']??'ready'),'created_at'=>$s['created_at']??null,'qa_approved_at'=>$s['qa_approved_at']??null,'promoted_at'=>$s['promoted_at']??null];
    }
    public static function initializeHost():array{return self::hostProject();}
    private static function hostProject():array{
        $domain=self::rootDomain();$q=db()->prepare('SELECT * FROM projects WHERE LOWER(primary_domain)=LOWER(?) ORDER BY is_system_project DESC,id ASC LIMIT 1');$q->execute([$domain]);$p=$q->fetch();
        if($p){db()->prepare("UPDATE projects SET is_system_project=1,source='system',metadata_json=JSON_SET(COALESCE(NULLIF(metadata_json,''),'{}'),'$.purpose','staging_host','$.managed_by','ramy') WHERE id=?")->execute([(int)$p['id']]);foreach(['ramy'=>'manage','ayman'=>'work','emad'=>'read','walid'=>'read'] as $slug=>$scope){$a=AgentService::bySlug($slug);db()->prepare("INSERT INTO agent_project_access(agent_id,project_id,access_scope) VALUES (?,?,?) ON DUPLICATE KEY UPDATE access_scope=VALUES(access_scope)")->execute([(int)$a['id'],(int)$p['id'],$scope]);}return ProjectService::get((int)$p['id']);}
        $uid=substr(hash('sha256','staging-host|'.$domain),0,16);
        db()->prepare("INSERT INTO projects(uid,name,primary_domain,status,workflow_stage,hosting_presence,source,is_system_project,metadata_json) VALUES (?,? ,?,'active','in_development','unknown','system',1,?)")->execute([$uid,'محطة الاختبار — '.$domain,$domain,j(['purpose'=>'staging_host','managed_by'=>'ramy'])]);
        $id=(int)db()->lastInsertId();
        foreach(['ramy'=>'manage','ayman'=>'work','emad'=>'read','walid'=>'read'] as $slug=>$scope){$a=AgentService::bySlug($slug);db()->prepare("INSERT INTO agent_project_access(agent_id,project_id,access_scope) VALUES (?,?,?) ON DUPLICATE KEY UPDATE access_scope=VALUES(access_scope)")->execute([(int)$a['id'],$id,$scope]);}
        return ProjectService::get($id);
    }
    private static function stagingSite(string $domain):array{
        $host=self::hostProject();$username=trim((string)($host['hosting_account']??''));
        if($username===''){try{$root=HostingerClient::siteByDomain(self::rootDomain());$username=(string)($root['username']??'');}catch(Throwable){}}
        if($username==='')throw new RuntimeException('hostinger_username_missing');
        return ['domain'=>$domain,'username'=>$username,'root'=>(string)($host['document_root']??''),'id'=>(string)($host['provider_website_id']??''),'type'=>'staging','status'=>'active','order_id'=>''];
    }
    private static function slug(array $p):string{
        $base='pb-'.(int)$p['id'].'-'.substr((string)($p['uid']??hash('sha256',(string)$p['id'])),0,6);$base=strtolower((string)preg_replace('/[^a-z0-9-]+/','-',$base));return substr(trim($base,'-'),0,55);
    }
    private static function cloneablePath(string $path,int $listedSize=0):bool{
        $path=ltrim(str_replace('\\','/',$path),'/');
        if($path===''||str_contains($path,'..'))return false;
        if($listedSize>2097152)return false;
        if(preg_match('#(?:^|/)(?:\.git|node_modules|vendor|cache|caches|logs?|backups?|tmp|storage/logs)(?:/|$)#i',$path))return false;
        // Never copy production credentials/configuration into the lab. The staging DB and
        // service credentials must be isolated and are wired separately from the encrypted vault.
        if(preg_match('#(?:^|/)(?:\.env(?:\.[^/]+)?|wp-config(?:-[^/]+)?\.php|config(?:\.[^/]+)?\.php|database\.php|db\.php|credentials?(?:\.[^/]+)?|secrets?(?:\.[^/]+)?|master\.key|secrets\.enc|\.htpasswd)(?:$|/)#i',$path))return false;
        $base=pb_strtolower(basename($path));
        if(in_array($base,['.htaccess','robots.txt'],true))return true;
        $ext=pb_strtolower(pathinfo($path,PATHINFO_EXTENSION));
        return in_array($ext,['php','html','htm','css','js','json','txt','md','xml','svg','jpg','jpeg','png','webp','gif','ico','woff','woff2','ttf','eot','pdf','map'],true);
    }
    /**
     * Best-effort, non-destructive source-code/media sync from the project's production webroot
     * to its isolated nourmakkah.com staging domain. Production secret/config files are never
     * copied because doing so could make staging talk to a production database or leak credentials.
     *
     * This is intentionally an internal file transfer: bytes are never sent to an AI model.
     */
    public static function syncFromProduction(int $projectId,bool $force=false):array{
        $p=ProjectService::get($projectId);
        if(!self::eligible($p))return ['required'=>false,'state'=>'production','files_copied'=>0,'files_unchanged'=>0,'files_skipped'=>0,'errors'=>[]];
        $st=self::existing($p);if(!$st)$st=self::ensure($projectId);
        $meta=self::metadata(ProjectService::get($projectId));$old=is_array($meta['staging']['source_sync']??null)?$meta['staging']['source_sync']:[];
        if(!$force&&!empty($old['completed_at']))return $old+['required'=>true,'skipped_sync'=>true];
        $sourceDomain=trim((string)($p['primary_domain']??''));if($sourceDomain==='')throw new RuntimeException('project_domain_missing');
        if(strcasecmp($sourceDomain,(string)$st['domain'])===0)throw new RuntimeException('staging_source_equals_target');
        $source=HostingerClient::siteByDomain($sourceDomain);$target=self::stagingSite((string)$st['domain']);
        $listing=HostingerClient::listFiles((string)$source['domain'],(string)$source['username'],'',8,3000);
        $candidates=[];$bytes=0;$skipped=0;$sensitiveSkipped=0;
        foreach((array)($listing['items']??[]) as $it){
            if(!is_array($it)||($it['type']??'')!=='file')continue;
            $path=ltrim((string)($it['path']??$it['name']??''),'/');$size=(int)($it['size_bytes']??$it['size']??0);
            if(!self::cloneablePath($path,$size)){
                $skipped++;
                if(preg_match('#(?:^|/)(?:\.env(?:\.[^/]+)?|wp-config(?:-[^/]+)?\.php|config(?:\.[^/]+)?\.php|database\.php|db\.php|credentials?|secrets?|master\.key|secrets\.enc|\.htpasswd)(?:$|/)#i',$path))$sensitiveSkipped++;
                continue;
            }
            $candidates[]=['path'=>$path,'size'=>$size];$bytes+=max(0,$size);
            if(count($candidates)>1200||$bytes>60*1024*1024)throw new RuntimeException('staging_source_sync_scope_too_large');
        }
        $copied=0;$unchanged=0;$errors=[];$copiedBytes=0;
        foreach($candidates as $f){
            try{
                $r=HostingerClient::readFile((string)$source['domain'],(string)$source['username'],(string)$f['path']);$content=(string)($r['content']??'');
                if(strlen($content)>2097152){$skipped++;continue;}
                $w=HostingerClient::writeFile($projectId,0,(string)$f['path'],$content,true,(string)$target['domain'],(string)$target['username'],true);
                if(!empty($w['changed'])){$copied++;$copiedBytes+=strlen($content);}else $unchanged++;
            }catch(Throwable $e){$errors[]=['path'=>(string)$f['path'],'error'=>pb_substr(Security::redactSecrets($e->getMessage()),0,180)];if(count($errors)>=30)break;}
        }
        $state=$errors?'completed_with_warnings':'completed';
        $sync=['required'=>true,'state'=>$state,'source_domain'=>$sourceDomain,'target_domain'=>(string)$st['domain'],'files_considered'=>count($candidates),'files_copied'=>$copied,'files_unchanged'=>$unchanged,'files_skipped'=>$skipped,'sensitive_files_skipped'=>$sensitiveSkipped,'bytes_copied'=>$copiedBytes,'errors'=>$errors,'completed_at'=>now_utc()];
        $p=ProjectService::get($projectId);$meta=self::metadata($p);$stage=is_array($meta['staging']??null)?$meta['staging']:[];$stage['source_sync']=$sync;$stage['source_sync_at']=$sync['completed_at'];if($errors)$stage['state']='ready_with_warnings';$meta['staging']=$stage;db()->prepare('UPDATE projects SET metadata_json=? WHERE id=?')->execute([j($meta),$projectId]);
        Audit::log('system','staging','staging.source_sync','project',(string)$projectId,$errors?'executed':'verified',$projectId,null,['source_domain'=>$sourceDomain,'target_domain'=>$st['domain'],'files_considered'=>count($candidates),'files_copied'=>$copied,'files_unchanged'=>$unchanged,'files_skipped'=>$skipped,'sensitive_files_skipped'=>$sensitiveSkipped,'error_count'=>count($errors)]);
        if($errors)Notifications::add('warning','projects','مزامنة Staging اكتملت بتحذيرات','تم نسخ '.$copied.' ملف إلى '.$st['domain'].' وتعذر '.count($errors).' ملف. الملفات الحساسة لا يتم نسخها من Production.','project',(string)$projectId);
        return $sync;
    }
    public static function ensure(int $projectId):array{
        $p=ProjectService::get($projectId);
        if(!self::eligible($p)){
            $domain=trim((string)($p['primary_domain']??''));if($domain==='')throw new RuntimeException('project_domain_missing');$site=HostingerClient::siteByDomain($domain);return ['domain'=>$site['domain'],'username'=>$site['username'],'directory'=>'','subdomain'=>'','state'=>'production','is_staging'=>false];
        }
        if($existing=self::existing($p)){
            $site=self::stagingSite((string)$existing['domain']);return $existing+['username'=>$site['username'],'is_staging'=>true];
        }
        $host=self::hostProject();$sub=self::slug($p);$dir=trim((string)setting('staging.directory_prefix','staging'),' /').'/'.$sub;$root=self::rootDomain();
        try{HostingerClient::createSubdomain((int)$host['id'],$sub,$dir,true);}catch(Throwable $e){$m=$e->getMessage();if(!str_contains($m,'409')&&!str_contains(pb_strtolower($m),'exist')&&!str_contains(pb_strtolower($m),'already'))throw $e;}
        $domain=$sub.'.'.$root;$meta=self::metadata($p);$meta['staging']=['domain'=>$domain,'subdomain'=>$sub,'directory'=>$dir,'state'=>'ready','created_at'=>now_utc(),'root_domain'=>$root];
        db()->prepare('UPDATE projects SET metadata_json=? WHERE id=?')->execute([j($meta),$projectId]);
        ProjectChatService::post($projectId,'system','staging','تم تجهيز محطة الاختبار للمشروع: https://'.$domain.'/','system','project',(string)$projectId,false,['domain'=>$domain,'directory'=>$dir]);
        Audit::log('agent',(string)AgentService::bySlug('ramy')['id'],'staging.prepare','project',(string)$projectId,'verified',$projectId,null,['domain'=>$domain,'directory'=>$dir]);
        $site=self::stagingSite($domain);$result=$meta['staging']+['username'=>$site['username'],'is_staging'=>true];
        if(trim((string)($p['primary_domain']??''))===''){
            // Greenfield/prototype project: there is intentionally no production source to clone.
            $result['source_sync']=['state'=>'blank_environment','required'=>false,'files_copied'=>0,'created_at'=>now_utc()];
            $p2=ProjectService::get($projectId);$m2=self::metadata($p2);$s2=is_array($m2['staging']??null)?$m2['staging']:[];$s2['state']='ready';$s2['source_sync']=$result['source_sync'];$m2['staging']=$s2;db()->prepare('UPDATE projects SET metadata_json=? WHERE id=?')->execute([j($m2),$projectId]);
        }else try{$result['source_sync']=self::syncFromProduction($projectId,false);}catch(Throwable $e){$result['source_sync']=['state'=>'failed','error'=>pb_substr(Security::redactSecrets($e->getMessage()),0,180)];$p2=ProjectService::get($projectId);$m2=self::metadata($p2);$s2=is_array($m2['staging']??null)?$m2['staging']:[];$s2['state']='source_sync_failed';$s2['source_sync']=$result['source_sync'];$m2['staging']=$s2;db()->prepare('UPDATE projects SET metadata_json=? WHERE id=?')->execute([j($m2),$projectId]);Notifications::add('warning','projects','تعذر نسخ ملفات المشروع إلى Staging','تم إنشاء البيئة لكن مزامنة الملفات لم تكتمل. لن يتم الادعاء أن البيئة جاهزة حتى تُراجع من صفحة الاختبارات.','project',(string)$projectId);}
        $fresh=self::existing(ProjectService::get($projectId));if($fresh)$result=array_merge($result,$fresh,['username'=>$site['username'],'is_staging'=>true]);
        return $result;
    }
    public static function executionTarget(array $project,bool $create=true):array{
        if(self::eligible($project)){
            if($create)return self::ensure((int)$project['id']);
            $s=self::existing($project);if($s){$site=self::stagingSite((string)$s['domain']);return $s+['username'=>$site['username'],'is_staging'=>true];}
        }
        $domain=trim((string)($project['primary_domain']??''));if($domain==='')throw new RuntimeException('project_domain_missing');$site=HostingerClient::siteByDomain($domain);return ['domain'=>$site['domain'],'username'=>$site['username'],'directory'=>'','subdomain'=>'','state'=>'production','is_staging'=>false];
    }
    public static function reviewTarget(int $projectId,array $execution=[]):array{
        $x=$execution['execution_target']??null;if(is_array($x)&&!empty($x['domain']))return $x;
        return self::executionTarget(ProjectService::get($projectId),false);
    }
    public static function markBuilt(int $projectId,int $taskId,array $target):void{
        $p=ProjectService::get($projectId);if(empty($target['is_staging']))return;$meta=self::metadata($p);$s=is_array($meta['staging']??null)?$meta['staging']:[];$s['state']='built';$s['last_build_task_id']=$taskId;$s['last_build_at']=now_utc();$meta['staging']=$s;db()->prepare('UPDATE projects SET metadata_json=?,last_deployment_at=NOW() WHERE id=?')->execute([j($meta),$projectId]);
    }
    public static function markQaApproved(int $projectId,int $reviewId):void{
        $p=ProjectService::get($projectId);if(!self::eligible($p))return;$meta=self::metadata($p);$s=is_array($meta['staging']??null)?$meta['staging']:[];if(empty($s['domain']))return;$s['state']='qa_approved';$s['qa_review_id']=$reviewId;$s['qa_approved_at']=now_utc();$meta['staging']=$s;db()->prepare('UPDATE projects SET metadata_json=? WHERE id=?')->execute([j($meta),$projectId]);
    }
    public static function needsPromotion(int $projectId):bool{
        $p=ProjectService::get($projectId);if(!self::eligible($p))return false;$s=self::existing($p);if(!$s||!in_array((string)$s['state'],['qa_approved','ready_delivery'],true))return false;$final=trim((string)($p['primary_domain']??''));return $final===''||strcasecmp($final,(string)$s['domain'])!==0;
    }
    /** يمنع النشر النهائي قبل اكتمال الدفع إذا كانت سياسة الشركة تتطلب ذلك. */
    private static function assertPaymentGate(int $projectId):array{
        $project=ProjectService::get($projectId);
        if((int)($project['is_system_project']??0)===1)return ['required'=>false,'ok'=>true,'reason'=>'system_project'];
        if(setting('customer.deliver_after_full_payment','1')!=='1')return ['required'=>false,'ok'=>true];
        $q=db()->prepare("SELECT * FROM quotes WHERE project_id=? AND owner_approved=1 AND status='accepted' ORDER BY id DESC LIMIT 1");$q->execute([$projectId]);$quote=$q->fetch();if(!$quote)throw new RuntimeException('delivery_quote_required');
        $p=db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE quote_id=? AND status='confirmed'");$p->execute([(int)$quote['id']]);$paid=(float)$p->fetchColumn();$total=(float)$quote['amount'];if($paid+0.0001<$total)throw new RuntimeException('delivery_payment_incomplete');
        return ['required'=>true,'ok'=>true,'quote_id'=>(int)$quote['id'],'project_value'=>$total,'confirmed_paid'=>$paid];
    }
    /** يجمع فقط SQL الذي نُفذ بنجاح على قاعدة Staging بعد آخر نشر. */
    private static function databasePromotionPlan(int $projectId,?string $after=null):array{
        $q=db()->prepare("SELECT * FROM project_databases WHERE project_id=? AND environment='staging' AND status<>'removed' ORDER BY id DESC LIMIT 1");$q->execute([$projectId]);$staging=$q->fetch();if(!$staging)return ['changed'=>false,'statements'=>[],'staging_database_id'=>null,'production_database_id'=>null];
        $sourceId=(int)($staging['source_database_id']??0);$sql="SELECT id,evidence_json,created_at FROM deployments WHERE project_id=? AND state='verified'";$args=[$projectId];if($after){$sql.=' AND created_at>?';$args[]=$after;}$sql.=' ORDER BY id ASC';$d=db()->prepare($sql);$d->execute($args);$rows=$d->fetchAll();$seen=[];$out=[];
        foreach($rows as $row){$ev=json_decode((string)($row['evidence_json']??''),true);if(!is_array($ev))continue;$db=$ev['database']??null;if(!is_array($db)||empty($db['changed']))continue;foreach((array)($db['statements']??[]) as $st){if(!is_array($st))continue;$statement=trim((string)($st['sql']??''));if($statement==='')continue;$hash=(string)($st['hash']??hash('sha256',$statement));if(isset($seen[$hash]))continue;$seen[$hash]=true;$out[]=['sql'=>$statement,'reason'=>(string)($st['reason']??'نقل تغيير معتمد من Staging إلى Production'),'hash'=>$hash,'deployment_id'=>(int)$row['id']];}}
        return ['changed'=>(bool)$out,'statements'=>$out,'staging_database_id'=>(int)$staging['id'],'production_database_id'=>$sourceId];
    }
    /** يطبق migrations المعتمدة فقط. جميعها تمر مرة أخرى عبر قيود ProjectDatabaseClient وBackup قبل التنفيذ. */
    private static function promoteDatabase(int $projectId,int $taskId,?string $after=null):array{
        $plan=self::databasePromotionPlan($projectId,$after);if(empty($plan['changed']))return ['changed'=>false,'statements'=>[]];$prodId=(int)($plan['production_database_id']??0);if($prodId<1)throw new RuntimeException('production_database_target_required');if(!ProjectDatabaseClient::configured($prodId))throw new RuntimeException('production_database_credentials_required');$ramy=AgentService::bySlug('ramy');$all=(array)$plan['statements'];if(count($all)>40)throw new RuntimeException('production_database_migration_scope_too_large');$batches=array_chunk($all,10);$applied=[];$backups=[];foreach($batches as $batch){$r=ProjectDatabaseClient::executePlan($prodId,$taskId,(int)$ramy['id'],$batch);if(!empty($r['backup']))$backups[]=$r['backup'];foreach((array)($r['statements']??[]) as $x)$applied[]=$x;}
        return ['changed'=>true,'production_database_id'=>$prodId,'staging_database_id'=>(int)$plan['staging_database_id'],'statements'=>$applied,'backups'=>$backups];
    }
    private static function productionFileMap(string $domain):array{
        try{$site=HostingerClient::siteByDomain($domain);$listing=HostingerClient::listFiles($site['domain'],$site['username'],'',8,3000);$map=[];foreach((array)($listing['items']??[]) as $it){if(!is_array($it)||($it['type']??'')!=='file')continue;$path=ltrim((string)($it['path']??$it['name']??''),'/');if($path!=='')$map[$path]=true;}return ['site'=>$site,'files'=>$map];}catch(Throwable $e){throw new RuntimeException('production_file_inventory_failed:'.$e->getMessage(),0,$e);}
    }
    private static function rollbackProductionFiles(int $projectId,int $taskId,array $writes,array $site):array{
        $restored=0;$failed=0;$created=[];foreach(array_reverse($writes) as $w){if(empty($w['changed']))continue;if(!empty($w['created'])){$created[]=(string)($w['path']??'');continue;}$backup=(string)($w['backup']??'');if($backup===''){$failed++;continue;}try{HostingerClient::restoreFileBackup($projectId,$taskId,(string)$w['path'],$backup,(string)$site['domain'],(string)$site['username']);$restored++;}catch(Throwable){$failed++;}}
        return ['restored'=>$restored,'failed'=>$failed,'created_files_not_auto_deleted'=>$created,'complete'=>$failed===0&&!$created];
    }
    /**
     * نشر نهائي guarded: QA + دفع + DB backup/migrations + file backups + rollback لما يمكن استرجاعه.
     * WordPress يظل محميًا لأن النقل الصحيح يحتاج معالجة URLs/serialized data مخصصة ولا يجوز ادعاء نجاحه بدون Connector لذلك.
     */
    public static function promote(int $projectId,int $taskId=0):array{
        $p=ProjectService::get($projectId);if(!self::eligible($p))throw new RuntimeException('staging_not_required');$s=self::existing($p);if(!$s)throw new RuntimeException('staging_not_prepared');if(!in_array((string)$s['state'],['qa_approved','ready_delivery'],true))throw new RuntimeException('staging_not_qa_approved');
        $final=trim((string)($p['primary_domain']??''));if($final==='')throw new RuntimeException('final_domain_missing');if(strcasecmp($final,(string)$s['domain'])===0)throw new RuntimeException('final_domain_is_staging');if(str_contains(pb_strtolower((string)($p['technology']??'')),'wordpress'))throw new RuntimeException('staging_wordpress_promotion_requires_managed_migration');$payment=self::assertPaymentGate($projectId);
        $source=self::stagingSite((string)$s['domain']);$listing=HostingerClient::listFiles($source['domain'],$source['username'],'',8,2500);$items=(array)($listing['items']??[]);$files=[];$total=0;
        foreach($items as $it){if(!is_array($it)||($it['type']??'')!=='file')continue;$path=ltrim((string)($it['path']??$it['name']??''),'/');if($path===''||preg_match('#(?:^|/)(?:\.git|node_modules|vendor|cache|logs?|backups?|tmp)(?:/|$)#i',$path)||preg_match('#(?:^|/)(?:\.env(?:\.[^/]+)?|wp-config(?:-[^/]+)?\.php|config(?:\.[^/]+)?\.php|credentials?(?:\.[^/]+)?|secrets?(?:\.[^/]+)?|master\.key|secrets\.enc)(?:$|/)#i',$path))continue;$size=(int)($it['size_bytes']??$it['size']??0);if($size>2097152)continue;$base=strtolower(basename($path));$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));if(!in_array($ext,['php','html','htm','css','js','json','txt','md','xml','svg','jpg','jpeg','png','webp','gif','ico','woff','woff2','ttf','eot','pdf','map'],true)&&$base!=='.htaccess')continue;$files[]=['path'=>$path,'size'=>$size];$total+=$size;if(count($files)>800||$total>40*1024*1024)throw new RuntimeException('staging_promotion_scope_too_large');}
        if(!$files)throw new RuntimeException('staging_no_files_to_promote');$prod=self::productionFileMap($final);$existing=$prod['files'];usort($files,static fn($a,$b)=>(isset($existing[$b['path']])<=>isset($existing[$a['path']]))?:strcmp($a['path'],$b['path']));
        /* additive/non-destructive DB migrations go first; if they fail no production file was changed. */
        $dbResult=self::promoteDatabase($projectId,$taskId,$s['promoted_at']??null);$writes=[];$changed=0;$skipped=0;$errors=[];
        foreach($files as $f){try{$r=HostingerClient::readFile($source['domain'],$source['username'],$f['path']);$bytes=(string)($r['content']??'');$w=HostingerClient::writeFile($projectId,$taskId,$f['path'],$bytes,true,(string)$prod['site']['domain'],(string)$prod['site']['username'],true);$writes[]=$w;if(!empty($w['changed']))$changed++;else $skipped++;}catch(Throwable $e){$errors[]=['path'=>$f['path'],'error'=>pb_substr($e->getMessage(),0,180)];break;}}
        if($errors){$rollback=self::rollbackProductionFiles($projectId,$taskId,$writes,$prod['site']);$detail=['errors'=>$errors,'rollback'=>$rollback,'database'=>$dbResult];Audit::log('agent',(string)AgentService::bySlug('ramy')['id'],'staging.promote','project',(string)$projectId,'failed',$projectId,$taskId?:null,$detail);throw new RuntimeException('staging_promotion_partial_failure:'.j($detail));}
        $p=ProjectService::get($projectId);$meta=self::metadata($p);$st=$meta['staging']??[];$st['state']='promoted';$st['promoted_at']=now_utc();$st['production_domain']=$final;$st['files_promoted']=$changed;$meta['staging']=$st;db()->prepare('UPDATE projects SET metadata_json=?,last_deployment_at=NOW() WHERE id=?')->execute([j($meta),$projectId]);
        $ev=['source_domain'=>$s['domain'],'production_domain'=>$final,'payment_gate'=>$payment,'files_total'=>count($files),'files_changed'=>$changed,'files_unchanged'=>$skipped,'database'=>$dbResult,'promoted_at'=>now_utc()];if($taskId>0)TaskEvidence::add($taskId,'deployment','نقل النسخة المعتمدة من محطة الاختبار إلى دومين العميل',$ev,'verified',(int)AgentService::bySlug('ramy')['id']);ProjectChatService::post($projectId,'agent','ramy','تم نقل النسخة المعتمدة من '.$s['domain'].' إلى '.$final.'. الملفات المتغيرة: '.$changed.'.','delivery','project',(string)$projectId,false,$ev);Audit::log('agent',(string)AgentService::bySlug('ramy')['id'],'staging.promote','project',(string)$projectId,'verified',$projectId,$taskId?:null,$ev);return $ev;
    }

}
