<?php
declare(strict_types=1);

final class RamiRepairService {
    private static function startRun(string $scope,?int $projectId,int $sessionId,string $request):int {
        db()->prepare("INSERT INTO ramy_repair_runs(scope_key,project_id,session_id,request_text,state,started_at) VALUES (?,?,?,?, 'running',NOW())")->execute([$scope,$projectId?:null,$sessionId?:null,pb_substr($request,0,6000)]);
        return (int)db()->lastInsertId();
    }

    private static function finishRun(int $runId,string $state,array $diagnosis,array $changes,string $summary,string $error=''):void {
        db()->prepare("UPDATE ramy_repair_runs SET state=?,diagnosis_json=?,changes_json=?,summary_text=?,error_text=?,completed_at=NOW(),updated_at=NOW() WHERE id=?")
            ->execute([$state,j($diagnosis),j($changes),pb_substr($summary,0,6000),$error!==''?pb_substr(Security::redactSecrets($error),0,1800):null,$runId]);
    }

    private static function companyProject():?array {
        $host=strtolower((string)(parse_url((string)config('app.base_url',''),PHP_URL_HOST)?:''));
        if($host==='')return null;
        $p=ProjectService::resolve($host,null);if($p)return $p;
        return null;
    }

    private static function projectInventory(array $project):array {
        $domain=(string)($project['primary_domain']??'');if($domain==='')throw new RuntimeException('project_domain_missing');
        $site=HostingerClient::siteByDomain($domain);$listing=HostingerClient::listFiles($site['domain'],$site['username'],'',5,1600);$items=[];
        foreach((array)($listing['items']??[]) as $it){
            if(!is_array($it))continue;$path=ltrim(str_replace('\\','/',(string)($it['path']??$it['name']??'')),'/');if($path==='')continue;
            $type=strtolower((string)($it['type']??$it['item_type']??''));if($type==='directory'||str_ends_with($path,'/'))continue;
            $ext=strtolower((string)pathinfo($path,PATHINFO_EXTENSION));
            if(!in_array($ext,['php','js','css','html','htm','json','txt','md','xml','sql'],true)&&basename($path)!=='.htaccess')continue;
            if(preg_match('#(?:^|/)(?:vendor|node_modules|cache|logs?|tmp|uploads?)/#i',$path))continue;
            $items[]=['path'=>$path,'size'=>(int)($it['size']??$it['size_bytes']??0),'modified_at'=>$it['modified_at']??$it['updated_at']??null];
            if(count($items)>=700)break;
        }
        return ['site'=>$site,'files'=>$items,'total'=>(int)($listing['total_items']??count($items))];
    }

    private static function recentSystemErrors():array {
        $rows=SystemDoctor::recentErrors(20);$out=[];
        foreach($rows as $r)$out[]=['ref'=>$r['error_ref']??'','uri'=>$r['request_uri']??'','class'=>$r['error_class']??'','message'=>Security::redactSecrets((string)($r['error_message']??''),500),'at'=>$r['created_at']??''];
        return $out;
    }

    private static function dbInventory(int $projectId,bool $system):array {
        if($system)return ['mode'=>'company_os','configured'=>true,'databases'=>[['id'=>0,'name'=>(string)config('db.name','company_os'),'configured'=>true]]];
        $q=db()->prepare("SELECT id,db_name,db_host,db_port,connection_state,status FROM project_databases WHERE project_id=? AND status='active' ORDER BY id");$q->execute([$projectId]);$rows=$q->fetchAll();
        foreach($rows as &$r){try{$r['configured']=ProjectDatabaseClient::configured((int)$r['id']);}catch(Throwable){$r['configured']=false;}}unset($r);
        return ['mode'=>'project','configured'=>(bool)array_filter($rows,fn($x)=>!empty($x['configured'])),'databases'=>$rows];
    }

    private static function chooseReadPaths(array $project,string $request,array $inventory,array $errors,array $dbInfo):array {
        $ramy=AgentService::bySlug('ramy');
        $schema=['type'=>'object','additionalProperties'=>false,'properties'=>[
            'diagnosis'=>['type'=>'string'],
            'read_paths'=>['type'=>'array','items'=>['type'=>'string']],
            'need_database'=>['type'=>'boolean'],
            'database_id'=>['type'=>'integer'],
            'verification_urls'=>['type'=>'array','items'=>['type'=>'string']]
        ],'required'=>['diagnosis','read_paths','need_database','database_id','verification_urls']];
        $files=array_slice((array)$inventory['files'],0,650);
        $payload=['request'=>$request,'project'=>['id'=>$project['id']??0,'name'=>$project['name']??'','domain'=>$project['primary_domain']??'','status'=>$project['status']??''],'file_inventory'=>$files,'recent_errors'=>$errors,'database'=>$dbInfo];
        $instructions="أنت رامي في وضع تشخيص تقني مباشر بأمر المالك. اختَر أقل عدد ملفات لازمة لفهم الخطأ، بحد أقصى 6. لا تطلب أو تقرأ ملفات أسرار أو .env أو config credentials أو master.key. استخدم الأخطاء الفعلية وأسماء الملفات، ولا تخمن ملفًا غير موجود في FILE_INVENTORY. لو المشكلة قد تكون في قاعدة البيانات اجعل need_database=true واختر database_id من القائمة فقط؛ Company OS يستخدم database_id=0. لا تقترح إصلاحًا الآن، فقط التشخيص ومسارات القراءة والتحقق.";
        $r=AiGateway::json($ramy,$instructions,[['role'=>'user','content'=>j($payload)]],$schema,'ramy_repair_reads',4200);$o=(array)$r['data'];
        $available=array_fill_keys(array_map(fn($x)=>(string)$x['path'],$files),true);$paths=[];
        foreach(array_slice((array)($o['read_paths']??[]),0,6) as $p){$p=ltrim(str_replace('\\','/',trim((string)$p)),'/');if($p!==''&&isset($available[$p]))$paths[]=$p;}
        return ['diagnosis'=>trim((string)($o['diagnosis']??'')),'read_paths'=>array_values(array_unique($paths)),'need_database'=>(bool)($o['need_database']??false),'database_id'=>(int)($o['database_id']??0),'verification_urls'=>array_slice((array)($o['verification_urls']??[]),0,5)];
    }

    private static function readSources(array $inventory,array $paths):array {
        $site=(array)$inventory['site'];$out=[];$total=0;
        foreach($paths as $path){
            try{$r=HostingerClient::readFile((string)$site['domain'],(string)$site['username'],(string)$path);$content=(string)($r['content']??'');$content=pb_substr($content,0,18000);$total+=strlen($content);if($total>70000)break;$out[]=['path'=>$path,'content'=>$content];}
            catch(Throwable $e){$out[]=['path'=>$path,'read_error'=>RamiErrorAdvisor::explain($e,'ramy_repair_project')];}
        }
        return $out;
    }

    private static function schemaForDatabase(int $projectId,bool $system,int $dbId,bool $needed):array {
        if(!$needed)return [];
        try{
            if($system){
                $pdo=db();$tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$out=[];
                foreach(array_slice($tables,0,100) as $t){$cols=$pdo->query('SHOW FULL COLUMNS FROM `'.str_replace('`','``',(string)$t).'`')->fetchAll();$out[]=['table'=>(string)$t,'columns'=>array_map(fn($c)=>['name'=>$c['Field']??'','type'=>$c['Type']??'','null'=>$c['Null']??'','key'=>$c['Key']??'','default'=>$c['Default']??null],$cols)];}
                return $out;
            }
            if($dbId<1)return [];$q=db()->prepare("SELECT id FROM project_databases WHERE id=? AND project_id=? AND status='active'");$q->execute([$dbId,$projectId]);if(!(int)$q->fetchColumn())throw new RuntimeException('database_not_found_in_project');
            return ProjectDatabaseClient::schemaSummary($dbId,100);
        }catch(Throwable $e){return [['schema_error'=>RamiErrorAdvisor::explain($e,'ramy_repair_project')]];}
    }

    private static function buildRepairPlan(array $project,string $request,array $diagnosis,array $sources,array $dbSchema,bool $system):array {
        $ramy=AgentService::bySlug('ramy');
        $schema=['type'=>'object','additionalProperties'=>false,'properties'=>[
            'root_cause'=>['type'=>'string'],'fix_explanation'=>['type'=>'string'],
            'files'=>['type'=>'array','items'=>['type'=>'object','additionalProperties'=>false,'properties'=>['path'=>['type'=>'string'],'new_content'=>['type'=>'string'],'reason'=>['type'=>'string']],'required'=>['path','new_content','reason']]],
            'database_statements'=>['type'=>'array','items'=>['type'=>'object','additionalProperties'=>false,'properties'=>['sql'=>['type'=>'string'],'reason'=>['type'=>'string']],'required'=>['sql','reason']]],
            'verification_paths'=>['type'=>'array','items'=>['type'=>'string']],
            'safe_to_apply'=>['type'=>'boolean'],'blocked_reason'=>['type'=>'string']
        ],'required'=>['root_cause','fix_explanation','files','database_statements','verification_paths','safe_to_apply','blocked_reason']];
        $payload=['owner_request'=>$request,'project'=>['id'=>$project['id']??0,'name'=>$project['name']??'','domain'=>$project['primary_domain']??''],'initial_diagnosis'=>$diagnosis,'sources'=>$sources,'database_schema'=>$dbSchema,'system_database'=>$system];
        $instructions="أنت رامي في وضع إصلاح فعلي وبإذن صريح من مالك النظام. المطلوب خطة قابلة للتطبيق لا شرح عام. بالنسبة للملفات: عدّل فقط الملفات التي تم إرسال محتواها لك، وأعد new_content كاملًا للملف بعد الإصلاح. لا تلمس أسرارًا أو .env أو ملفات مفاتيح. حافظ على التوافق مع PHP 8.1+ وعلى الستايل الحالي، ولا تحذف وظائف غير مرتبطة بالخطأ. بالنسبة لقاعدة البيانات: لا تستخدم DROP/DELETE/TRUNCATE/RENAME أو أوامر مستخدمين/صلاحيات؛ استخدم فقط CREATE TABLE/INDEX أو ALTER ADD أو INSERT أو UPDATE مع WHERE عند الحاجة. لا تعدل users/audit_logs/backups. لو الدليل غير كافٍ اجعل safe_to_apply=false واشرح ما ينقص بدل التخمين. لو safe_to_apply=true يجب أن يكون السبب والإصلاح محددين وقابلين للتحقق.";
        $r=AiGateway::json($ramy,$instructions,[['role'=>'user','content'=>j($payload)]],$schema,'ramy_repair_plan',8500);return (array)$r['data'];
    }

    private static function applyFileChanges(int $projectId,int $runId,array $allowedSources,array $files):array {
        $readable=array_values(array_filter($allowedSources,fn($x)=>is_array($x)&&array_key_exists('content',$x)));$allowed=array_fill_keys(array_map(fn($x)=>(string)($x['path']??''),$readable),true);$out=[];$taskId=0;
        foreach(array_slice($files,0,4) as $f){if(!is_array($f))continue;$path=ltrim(str_replace('\\','/',trim((string)($f['path']??''))),'/');$content=(string)($f['new_content']??'');if($path===''||$content===''||!isset($allowed[$path]))continue;$r=HostingerClient::writeFile($projectId,$taskId,$path,$content,false);$out[]=['path'=>$path,'changed'=>(bool)($r['changed']??false),'backup'=>$r['backup']??null,'reason'=>pb_substr((string)($f['reason']??''),0,600),'after_hash'=>$r['after_hash']??$r['hash']??null];}
        return $out;
    }

    private static function applyDatabaseChanges(int $projectId,int $runId,bool $system,int $dbId,array $statements):array {
        if(!$statements)return ['changed'=>false,'statements'=>[]];
        if($system)return SystemDatabaseRepair::executePlan($runId,$statements);
        if($dbId<1)throw new RuntimeException('project_database_credentials_required');
        $ramy=AgentService::bySlug('ramy');return ProjectDatabaseClient::executePlan($dbId,0,(int)$ramy['id'],$statements);
    }

    private static function verify(array $project,bool $system,array $paths):array {
        $out=['http'=>null,'system'=>null,'paths'=>[]];$domain=(string)($project['primary_domain']??'');
        if($domain!==''){try{$p=HttpClient::probe('https://'.$domain,20,32768);$out['http']=['status'=>$p['status']??0,'ok'=>(int)($p['status']??0)>=200&&(int)($p['status']??0)<500,'final_url'=>$p['final_url']??''];}catch(Throwable $e){$out['http']=['ok'=>false,'error'=>RamiErrorAdvisor::explain($e)];}}
        if($system){try{$r=SystemDoctor::report();$out['system']=['ok'=>$r['ok']??false,'schema_issues'=>$r['schema_issues']??[],'failed_checks'=>array_keys(array_filter((array)($r['checks']??[]),fn($x)=>empty($x['ok'])))];}catch(Throwable $e){$out['system']=['ok'=>false,'error'=>RamiErrorAdvisor::explain($e)];}}
        if($domain!==''&&$paths){try{$site=HostingerClient::siteByDomain($domain);foreach(array_slice($paths,0,5) as $path){try{$f=HostingerClient::readFile($site['domain'],$site['username'],(string)$path);$out['paths'][]=['path'=>$path,'ok'=>isset($f['content'])];}catch(Throwable $e){$out['paths'][]=['path'=>$path,'ok'=>false,'error'=>RamiErrorAdvisor::explain($e)];}}}catch(Throwable){} }
        return $out;
    }

    public static function diagnoseSystem(int $sessionId,string $request):array {
        $project=self::companyProject();$report=SystemDoctor::report();$errors=self::recentSystemErrors();$runtime=RuntimeAuditService::snapshot();$issues=(array)($runtime['issues']??[]);
        $summary='فحصت Company OS من قاعدة البيانات وسجل التشغيل: '.(($report['ok']??false)?'الفحوص الأساسية سليمة':'فيه نقاط محتاجة تدخل').'.';
        if($issues){$summary.=' أهم المشاكل الحالية: ';$parts=[];foreach(array_slice($issues,0,6) as $x)$parts[]=(string)($x['title']??$x['code']??'خطأ');$summary.=implode('، ',$parts).'.';}
        if(!empty($report['schema_issues']))$summary.=' مشاكل قاعدة البيانات: '.implode('، ',array_slice($report['schema_issues'],0,6)).'.';
        if($errors)$summary.=' آخر Exception مسجل: '.($errors[0]['message']??'غير معروف').'.';
        if($project)$summary.=' مشروع Hostinger المرتبط: '.($project['primary_domain']??$project['name']).'.';
        return ['summary'=>$summary,'primary_task'=>null,'report'=>$report,'runtime_audit'=>$runtime,'errors'=>$errors,'project_id'=>$project['id']??null];
    }

    public static function repairWhatsApp(int $sessionId,string $request):array{
        $runId=self::startRun('whatsapp',null,$sessionId,$request);$diag=[];$changes=[];
        try{
            // WhatsApp-only repair. Never inventory all Hostinger files for a WhatsApp request.
            try{$issues=SystemDoctor::schemaIssues();$diag['schema_issues']=$issues;if($issues)$changes['schema_repair']=SchemaRepair::applyPackageUpgrade();}catch(Throwable $e){$diag['schema_check_error']=AdminUi::humanError(Security::redactSecrets($e->getMessage()));}
            $requiredFiles=['src/MetaClient.php','src/WhatsAppPolicy.php','src/CommunicationGateway.php','webhooks/meta.php','src/Scheduler.php','src/Worker.php'];$diag['runtime_files']=[];
            foreach($requiredFiles as $rel){$full=PB_ROOT.'/public_html/api/'.$rel;$diag['runtime_files'][]=['file'=>$rel,'exists'=>is_file($full),'readable'=>is_readable($full),'size'=>is_file($full)?(int)@filesize($full):0];}
            try{$counts=db()->query("SELECT state,COUNT(*) c FROM whatsapp_pending_messages GROUP BY state")->fetchAll();$diag['queue_counts']=[];foreach($counts as $r)$diag['queue_counts'][(string)$r['state']]=(int)$r['c'];}catch(Throwable $e){$diag['queue_counts_error']=AdminUi::humanError(Security::redactSecrets($e->getMessage()));}
            try{$q=db()->query("SELECT ce.details_json,ce.created_at,m.id message_id,c.customer_id FROM communication_events ce LEFT JOIN messages m ON m.id=ce.message_id LEFT JOIN conversations c ON c.id=m.conversation_id WHERE ce.event_type='delivery_status' AND ce.state='failed' ORDER BY ce.id DESC LIMIT 1");$lf=$q->fetch();if($lf){$raw=json_decode((string)$lf['details_json'],true)?:[];$diag['last_delivery_failure']=['at'=>$lf['created_at'],'message_id'=>$lf['message_id'],'customer_id'=>$lf['customer_id'],'reason'=>WhatsAppPolicy::humanFailure(['raw'=>$raw]),'meta'=>WhatsAppPolicy::metaFailure(['raw'=>$raw])];}}catch(Throwable){}

            $heartbeat=(string)setting('runtime.worker_heartbeat_at','');$workerHealthy=$heartbeat!==''&&utc_ts($heartbeat)!==false&&utc_ts($heartbeat)>=time()-360;$diag['worker']=['heartbeat_at'=>$heartbeat?:null,'healthy'=>$workerHealthy];
            if(!$workerHealthy){try{$changes['worker_cron_checked']=Scheduler::ensureWorkerCronIfStale(360);$diag['worker']['cron_repair_attempted']=true;}catch(Throwable $e){$diag['worker']['cron_repair_error']=AdminUi::humanError(Security::redactSecrets($e->getMessage()));}}

            // Hard-repair the exact production failure #131058: hello_world is only for Meta public test numbers.
            $rawConfigured=trim((string)setting('whatsapp.customer_template_name',''));
            $lastCode=(int)($diag['last_delivery_failure']['meta']['code']??0);
            if(WhatsAppPolicy::isReservedTemplate($rawConfigured)||$lastCode===131058){
                if($rawConfigured!==''){$changes['sample_template_removed']=$rawConfigured;put_setting('whatsapp.template_last_invalid_name',$rawConfigured);}
                put_setting('whatsapp.customer_template_name','');
                put_setting('whatsapp.template_submission_state','INVALID_SAMPLE');
                put_setting('whatsapp.template_last_failure_code','131058');
                $changes['sample_template_queue_reset']=WhatsAppPolicy::recoverFailedQueue();
            }

            // This separates "Ramy can reply to the owner" from "the business can proactively start a customer chat".
            $readiness=MetaClient::customerOutboundReadiness();$diag['customer_outbound_readiness']=$readiness;
            if(empty($readiness['messaging_ok']))throw new RuntimeException((string)($readiness['reason']?:'meta_messaging_unavailable'));
            $diag['meta_connection']=['ok'=>true,'phone'=>$readiness['phone']??null,'verified_name'=>$readiness['verified_name']??null];
            $waba=(string)($readiness['waba_id']??'');$diag['waba_id_present']=$waba!=='';

            $configuredName=WhatsAppPolicy::templateName();$configuredLang=WhatsAppPolicy::templateLanguage();$submission=null;
            $managementOk=!empty($readiness['template_management_ok']);$managementCode=(string)($readiness['template_management_code']??'');
            $approved=[];
            if($managementOk){
                try{$approved=MetaClient::approvedTemplates();}catch(Throwable $e){$managementOk=false;$managementCode='meta_template_management_probe_failed';$diag['template_management_runtime_error']=AdminUi::humanError($e->getMessage());}
            }

            if($managementOk){
                $configuredApproved=false;
                foreach($approved as $t){if((string)($t['name']??'')===$configuredName&&(string)($t['language']??'')===$configuredLang){$configuredApproved=true;break;}}
                if($configuredName!==''&&!$configuredApproved){put_setting('whatsapp.customer_template_name','');put_setting('whatsapp.template_submission_state','INVALID');$changes['invalid_template_cleared']=$configuredName;$configuredName='';}
                if($configuredName===''){$changes['auto_template_selected']=WhatsAppPolicy::autoSelectTemplate();$configuredName=WhatsAppPolicy::templateName();}
                if($configuredName===''){
                    try{$submission=MetaClient::ensureOpeningTemplate();$changes['opening_template']=$submission;if(strtoupper((string)($submission['state']??''))==='APPROVED')$configuredName=WhatsAppPolicy::templateName();}
                    catch(Throwable $e){$changes['opening_template_error']=RamiErrorAdvisor::explain($e,'ramy_repair_whatsapp');}
                }
            }

            // If the token can send WhatsApp but cannot list/manage templates, do not deadlock the queue.
            // A manually configured exact template name is validated by a real template send + delivery webhook.
            if(!$managementOk&&$configuredName===''){
                $diag['template']=['name'=>null,'language'=>$configuredLang,'approved_count'=>0,'management_ok'=>false,'management_code'=>$managementCode?:'meta_template_management_probe_failed','submission'=>null];
                $changes['queue_recovered']=WhatsAppPolicy::recoverFailedQueue();
                $problem=$managementCode==='meta_template_management_permission_missing'
                    ?'مفتاح Meta الحالي يقدر يرسل رسائل WhatsApp، لكنه لا يملك صلاحية إدارة/قراءة قوالب WhatsApp.'
                    :'النظام يقدر يرسل WhatsApp، لكن تعذر التحقق من قوالب WABA أو إدارتها بهذا الربط.';
                $fix=$managementCode==='meta_template_management_permission_missing'
                    ?'أضف whatsapp_business_management لنفس System User/Token، أو اكتب يدويًا اسم قالب Approved ولغته في صفحة الربط؛ بعدها النظام سيختبر القالب بإرسال حقيقي ويعتمد Webhook sent/delivered/read كدليل.'
                    :'اضبط WABA/صلاحية إدارة القوالب أو اكتب اسم قالب Approved معروف يدويًا ثم شغّل اختبار قالب الافتتاح.';
                $summary='راجعت مسار WhatsApp نفسه فقط. الخطأ: '.$problem.' سبب إن رامي بيكلمك عادي هو إن رسائلك داخل مسار خدمة مفتوح، أما بدء محادثة عميل خارج 24 ساعة فيحتاج قالب Approved. الإصلاح المطلوب: '.$fix.' الرسائل الأصلية للعملاء ما زالت محفوظة في الطابور ولن تعتبر ناجحة قبل Webhook حقيقي.';
                self::finishRun($runId,'needs_input',$diag,$changes,$summary);Notifications::add('warning','communications','WhatsApp العملاء يحتاج صلاحية/قالب',$summary,'session',(string)$sessionId);return ['summary'=>$summary,'state'=>'needs_input','primary_task'=>null,'repair_run_id'=>$runId,'diagnosis'=>$diag,'changes'=>$changes];
            }

            $state=strtoupper((string)($submission['state']??setting('whatsapp.template_submission_state','')));
            $diag['template']=['name'=>$configuredName?:null,'language'=>WhatsAppPolicy::templateLanguage(),'approved_count'=>count($approved),'management_ok'=>$managementOk,'management_code'=>$managementCode?:null,'submission'=>$submission,'verified_by_delivery'=>!empty($readiness['template_verified_by_delivery'])];
            $changes['queue_recovered']=WhatsAppPolicy::recoverFailedQueue();
            $queue=['recovered_failed'=>0,'customers'=>0,'opened_window_sent'=>0,'templates_sent'=>0,'waiting_reply'=>0,'failed'=>0,'details'=>[]];
            if($configuredName!==''){$queue=CommunicationGateway::recoverWhatsAppQueue(12);$changes['queue']=$queue;}

            if($configuredName===''){
                if($state==='PENDING'){
                    $name=(string)($submission['name']??setting('whatsapp.template_submission_name','elmetr_project_contact'));
                    $summary='الخطأ: بدء محادثة العملاء خارج 24 ساعة متوقف لأن قالب الافتتاح «'.$name.'» ما زال PENDING عند Meta. السبب: Meta لا تسمح بنص حر خارج نافذة الخدمة. الإصلاح الذي نفذته: اتصال الرسائل نفسه سليم، استرجعت الرسائل المتوقفة وحفظتها، وعامل التشغيل يراجع الاعتماد تلقائيًا. المتبقي الخارجي الوحيد هو موافقة Meta على القالب؛ بعدها سيُرسل القالب تلقائيًا ثم تُستكمل الرسالة الأصلية بعد رد العميل.';
                    self::finishRun($runId,'waiting_external',$diag,$changes,$summary);Notifications::add('warning','communications','قالب WhatsApp تحت مراجعة Meta',$summary,'session',(string)$sessionId);return ['summary'=>$summary,'state'=>'waiting_external','primary_task'=>null,'repair_run_id'=>$runId,'diagnosis'=>$diag,'changes'=>$changes];
                }
                $extra='';if(!empty($changes['opening_template_error']['technical']))$extra=' تعذر تجهيز القالب تلقائيًا بسبب: '.AdminUi::humanError((string)$changes['opening_template_error']['technical']).'.';
                $summary='الخطأ: لا يوجد قالب WhatsApp Approved صالح لبدء محادثة العميل خارج 24 ساعة. السبب: Meta تمنع النص الحر خارج النافذة. الإصلاح: اتصال الرسائل شغال والرسائل الأصلية محفوظة في الطابور.'.$extra.' اضبط قالب Approved أو صلاحية إدارة القوالب؛ النظام سيعيد المحاولة تلقائيًا بعد ذلك.';
                self::finishRun($runId,'needs_input',$diag,$changes,$summary);Notifications::add('warning','communications','WhatsApp محتاج قالب Approved',$summary,'session',(string)$sessionId);return ['summary'=>$summary,'state'=>'needs_input','primary_task'=>null,'repair_run_id'=>$runId,'diagnosis'=>$diag,'changes'=>$changes];
            }

            $verifiedName=(string)setting('whatsapp.template_verified_name','');$verifiedAt=(string)setting('whatsapp.template_verified_delivery_at','');$templateVerified=$verifiedAt!==''&&hash_equals($configuredName,$verifiedName);
            if((int)($queue['failed']??0)>0&&(int)($queue['templates_sent']??0)===0&&(int)($queue['opened_window_sent']??0)===0){
                $details=(array)($queue['details']??[]);$first=$details[0]['error']??($diag['last_delivery_failure']['reason']??'فشل إرسال القالب');
                $summary='الخطأ: ما زال إرسال WhatsApp للعملاء يفشل أثناء محاولة القالب. السبب المسجل: '.(string)$first.'. الإصلاح الذي نفذته: رجعت الرسائل للطابور ومنعت اعتبار accepted نجاحًا، وسجلت الفشل الحقيقي. المطلوب الآن إصلاح القالب/صلاحية Meta الظاهرة في السبب ثم إعادة المحاولة؛ لن أقول إن العميل استلم قبل sent/delivered/read.';
                self::finishRun($runId,'failed',$diag,$changes,$summary,(string)$first);Notifications::add('critical','communications','WhatsApp العملاء ما زال يفشل',$summary,'session',(string)$sessionId);return ['summary'=>$summary,'state'=>'failed','primary_task'=>null,'repair_run_id'=>$runId,'diagnosis'=>$diag,'changes'=>$changes];
            }

            $statusText=$templateVerified?'القالب تم التحقق منه سابقًا بواسطة Webhook تسليم':'القالب مُهيأ/تم تقديمه للإرسال وننتظر Webhook لإثبات التسليم';
            $summary='راجعت وأصلحت مسار WhatsApp للعملاء من غير فهرسة Hostinger عامة. اتصال Meta للرسائل شغال. قالب الافتتاح المستخدم: '.$configuredName.'؛ '.$statusText.'. استرجعت '.(int)$changes['queue_recovered'].' رسالة متوقفة؛ قدمت '.(int)($queue['templates_sent']??0).' قالب افتتاح إلى Meta، وأرسلت '.(int)($queue['opened_window_sent']??0).' رسالة محفوظة داخل نافذة مفتوحة، وفي انتظار رد '.(int)($queue['waiting_reply']??0).' عميل.'.(!empty($diag['last_delivery_failure']['reason'])?' آخر سبب فشل قديم مسجل: '.$diag['last_delivery_failure']['reason'].'.':'').' أي قبول أولي لا يُحسب وصولًا؛ الدليل النهائي هو sent/delivered/read من Webhook.';
            self::finishRun($runId,'completed',$diag,$changes,$summary);Notifications::add('success','communications','رامي أنهى فحص WhatsApp',$summary,'session',(string)$sessionId);Audit::log('agent',(string)AgentService::bySlug('ramy')['id'],'ramy.repair','whatsapp',(string)$runId,'verified',null,null,['templates_sent'=>$queue['templates_sent']??0,'pending_sent'=>$queue['opened_window_sent']??0]);return ['summary'=>$summary,'state'=>'completed','primary_task'=>null,'repair_run_id'=>$runId,'diagnosis'=>$diag,'changes'=>$changes];
        }catch(Throwable $e){$advice=RamiErrorAdvisor::explain($e,'ramy_repair_whatsapp');$summary='إصلاح WhatsApp اتوقف عند خطأ حقيقي. الخطأ: '.AdminUi::humanError($e->getMessage()).' السبب التقني: '.pb_substr(Security::redactSecrets((string)$advice['technical']),0,240).'. الإصلاح: راجع نفس بند الربط الظاهر في الخطأ ثم أعد التشخيص؛ لم أسجل العملية كناجحة.';self::finishRun($runId,'failed',$diag,$changes,$summary,$e->getMessage());Notifications::add('critical','communications','إصلاح WhatsApp اتوقف',$summary,'session',(string)$sessionId);throw new RuntimeException('ramy_whatsapp_repair_failed:'.$advice['code'].':'.$advice['technical'],0,$e);}
    }

    public static function repairSystem(int $sessionId,string $request):array {
        $project=self::companyProject();if(!$project)throw new RuntimeException('project_context_missing');
        return self::repair((int)$project['id'],$sessionId,$request,true);
    }

    public static function repairProject(int $projectId,int $sessionId,string $request):array {
        if($projectId<1)throw new RuntimeException('project_context_missing');return self::repair($projectId,$sessionId,$request,false);
    }

    private static function repair(int $projectId,int $sessionId,string $request,bool $system):array {
        $project=ProjectService::get($projectId);$scope=$system?'company_os':'project';$runId=self::startRun($scope,$projectId,$sessionId,$request);$diag=[];$changes=[];
        try{
            if($system){$issues=SystemDoctor::schemaIssues();if($issues){$up=SchemaRepair::applyPackageUpgrade();$changes['schema_repair']=$up;}}
            $inventory=self::projectInventory($project);$errors=$system?self::recentSystemErrors():[];$dbInfo=self::dbInventory($projectId,$system);
            $diag=self::chooseReadPaths($project,$request,$inventory,$errors,$dbInfo);$sources=self::readSources($inventory,$diag['read_paths']);$dbSchema=self::schemaForDatabase($projectId,$system,(int)$diag['database_id'],(bool)$diag['need_database']);
            $plan=self::buildRepairPlan($project,$request,$diag,$sources,$dbSchema,$system);$diag['root_cause']=$plan['root_cause']??'';$diag['fix_explanation']=$plan['fix_explanation']??'';
            if(empty($plan['safe_to_apply'])){$summary='حددت المشكلة لكن وقفت قبل التعديل لأن الدليل مش كفاية: '.trim((string)($plan['blocked_reason']??'محتاج بيانات أكثر.'));self::finishRun($runId,'needs_input',$diag,$changes,$summary);return ['summary'=>$summary,'primary_task'=>null,'repair_run_id'=>$runId,'diagnosis'=>$diag];}
            $changes['files']=self::applyFileChanges($projectId,$runId,$sources,(array)($plan['files']??[]));
            $changes['database']=self::applyDatabaseChanges($projectId,$runId,$system,(int)$diag['database_id'],(array)($plan['database_statements']??[]));
            $verifyPaths=array_values(array_unique(array_merge((array)($plan['verification_paths']??[]),array_map(fn($x)=>(string)($x['path']??''),(array)$changes['files']))));$changes['verification']=self::verify($project,$system,$verifyPaths);
            $changedFiles=count(array_filter((array)$changes['files'],fn($x)=>!empty($x['changed'])));$dbChanged=!empty($changes['database']['changed']);
            $summary='شخّصت الخطأ ونفذت الإصلاح بأمر منك. السبب: '.trim((string)($plan['root_cause']??'تم تحديده من السجل والكود')).'. الإصلاح: '.trim((string)($plan['fix_explanation']??'تم تطبيق التغييرات اللازمة')).'.';
            $summary.=' الملفات المعدلة: '.$changedFiles.'. قاعدة البيانات: '.($dbChanged?'تم تعديلها بعد Backup':'لم تحتج تعديل').'.';
            $httpOk=(bool)($changes['verification']['http']['ok']??false);$summary.=' التحقق بعد الإصلاح: '.($httpOk?'الموقع استجاب':'يحتاج متابعة فحص HTTP').'.';
            self::finishRun($runId,'completed',$diag,$changes,$summary);Notifications::add('success','ramy','رامي أنهى إصلاحًا مباشرًا',$summary,'project',(string)$projectId);Audit::log('agent',(string)AgentService::bySlug('ramy')['id'],'ramy.repair',$scope,(string)$runId,'verified',$projectId,null,['files'=>$changedFiles,'database_changed'=>$dbChanged]);
            return ['summary'=>$summary,'primary_task'=>null,'repair_run_id'=>$runId,'diagnosis'=>$diag,'changes'=>$changes];
        }catch(Throwable $e){$advice=RamiErrorAdvisor::explain($e,$system?'ramy_repair_system':'ramy_repair_project');$summary='الإصلاح اتوقف عند خطأ حقيقي. '.RamiErrorAdvisor::ownerText($e,$system?'ramy_repair_system':'ramy_repair_project');self::finishRun($runId,'failed',$diag,$changes,$summary,$e->getMessage());Notifications::add('critical','ramy','إصلاح رامي اتوقف',$summary,'project',(string)$projectId);throw new RuntimeException('ramy_repair_failed:'.$advice['code'].':'.$advice['technical'],0,$e);}
    }
}
