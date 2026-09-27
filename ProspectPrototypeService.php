<?php
declare(strict_types=1);

/** Closed-loop Walid -> Ayman -> Emad -> Ramy prototype workflow for qualified public prospects. */
final class ProspectPrototypeService {
    public static function request(int $leadId,bool $ownerAuthorized=false):array{
        $walid=AgentService::bySlug('walid');Permissions::requireAgent((int)$walid['id'],'prototype.request');$lead=self::lead($leadId);
        if(!in_array((string)$lead['website_state'],['none','broken','weak'],true))throw new RuntimeException('prototype_requires_missing_broken_or_weak_website');
        if((int)$lead['score']<max(40,min(95,(int)setting('prospecting.prototype_min_score','65'))))throw new RuntimeException('prototype_lead_score_too_low');
        $q=db()->prepare('SELECT * FROM prospect_prototypes WHERE lead_id=?');$q->execute([$leadId]);$existing=$q->fetch();if($existing&&in_array((string)$existing['state'],['prototype_requested','prototype_building','prototype_qa','prototype_ready','ramy_outreach','contacted','converted'],true))return $existing+['existing'=>true];
        $opportunityId=(int)($lead['opportunity_id']??0);if(!$opportunityId)$opportunityId=ProspectingService::convert($leadId);
        $projectId=(int)($existing['project_id']??0);if(!$projectId)$projectId=self::createProject($lead,$opportunityId);
        $brief=['purpose'=>'نموذج أولي سريع لإثبات قيمة الخدمة قبل تواصل رامي مع الشركة.','requirements'=>self::briefText($lead),'scope'=>self::briefText($lead),'final_scope'=>self::briefText($lead),'out_of_scope'=>'لا نشر على دومين العميل، لا استخدام بيانات خاصة، لا تواصل خارجي من أيمن/عماد.','design_direction'=>'خفيف، سريع، Mobile First، يعكس نشاط الشركة الحقيقي من المعلومات العامة فقط.','hosting'=>'محطة الاختبار nourmakkah.com فقط','acceptance_criteria'=>['رابط Staging يعمل','لا توجد أخطاء 500','Responsive','CTA واضح','لا أسرار Production','مراجعة عماد قبل التواصل']];
        MasterBriefService::create($projectId,$brief,$ownerAuthorized);
        $desc="ابنِ Prototype خفيفًا وسريعًا للشركة المحتملة التالية على محطة nourmakkah.com فقط. لا تنشر على أي Production ولا تتواصل مع الشركة. استخدم المعلومات العامة في الـMaster Brief.\n".self::briefText($lead);
        $ay=TaskService::create('ayman','Prototype استباقي — '.(string)$lead['company_name'],$desc,$projectId,['job_kind'=>'ayman_execute','prototype_lead_id'=>$leadId,'prototype_workflow'=>1,'auto_review'=>1,'fix_cycle'=>0,'request'=>$desc],'high',$ownerAuthorized,null,'agent',(string)$walid['id']);
        $row=['lead_id'=>$leadId,'opportunity_id'=>$opportunityId,'project_id'=>$projectId,'ayman_task_id'=>$ay,'state'=>'prototype_building','brief_json'=>j($brief)];
        db()->prepare("INSERT INTO prospect_prototypes(lead_id,opportunity_id,project_id,ayman_task_id,state,brief_json) VALUES (?,?,?,?,'prototype_building',?) ON DUPLICATE KEY UPDATE opportunity_id=VALUES(opportunity_id),project_id=VALUES(project_id),ayman_task_id=VALUES(ayman_task_id),state='prototype_building',brief_json=VALUES(brief_json),updated_at=NOW()")
            ->execute([$leadId,$opportunityId,$projectId,$ay,j($brief)]);
        db()->prepare("UPDATE prospect_leads SET status='qualified',updated_at=NOW() WHERE id=?")->execute([$leadId]);
        try{ProjectChatService::post($projectId,'agent','walid','تم تأهيل الشركة وطلب Prototype من أيمن في المهمة #'.$ay.'. لن يتم التواصل الخارجي قبل مراجعة عماد وتسليم الرابط لرامي.','handoff','prospect',(string)$leadId,false,['lead_id'=>$leadId,'opportunity_id'=>$opportunityId]);}catch(Throwable){}
        return ['lead_id'=>$leadId,'opportunity_id'=>$opportunityId,'project_id'=>$projectId,'ayman_task_id'=>$ay,'state'=>'prototype_building'];
    }
    public static function linkEmadTask(int $aymanTaskId,int $emadTaskId):void{
        db()->prepare("UPDATE prospect_prototypes SET emad_task_id=?,state='prototype_qa',updated_at=NOW() WHERE ayman_task_id=?")->execute([$emadTaskId,$aymanTaskId]);
    }
    public static function afterTask(int $taskId,string $status,array $evidence=[],string $summary=''):void{
        $q=db()->prepare('SELECT * FROM prospect_prototypes WHERE ayman_task_id=? OR emad_task_id=? OR ramy_task_id=? ORDER BY id DESC LIMIT 1');$q->execute([$taskId,$taskId,$taskId]);$p=$q->fetch();if(!$p)return;
        if((int)$p['ayman_task_id']===$taskId){
            if($status==='needs_review'||$status==='completed'){$url=self::stagingUrl((int)$p['project_id']);db()->prepare("UPDATE prospect_prototypes SET state='prototype_qa',staging_url=COALESCE(?,staging_url),updated_at=NOW() WHERE id=?")->execute([$url?:null,(int)$p['id']]);}
            elseif(in_array($status,['failed','cancelled'],true))db()->prepare("UPDATE prospect_prototypes SET state='failed',qa_json=?,updated_at=NOW() WHERE id=?")->execute([j(['stage'=>'ayman','status'=>$status,'summary'=>$summary,'evidence'=>$evidence]),(int)$p['id']]);return;
        }
        if((int)($p['emad_task_id']??0)===$taskId){
            if($status==='completed'){
                $review=self::reviewForAyman((int)$p['ayman_task_id']);$approved=$review&&in_array((string)$review['status'],['approved','approved_warning'],true);if($approved){$url=self::stagingUrl((int)$p['project_id']);$ramy=self::queueRamy($p,$url,$review);db()->prepare("UPDATE prospect_prototypes SET state='ramy_outreach',staging_url=?,ramy_task_id=?,qa_json=?,updated_at=NOW() WHERE id=?")->execute([$url?:null,$ramy,j(['review'=>$review,'evidence'=>$evidence]),(int)$p['id']]);}else db()->prepare("UPDATE prospect_prototypes SET state='prototype_qa',qa_json=?,updated_at=NOW() WHERE id=?")->execute([j(['review'=>$review,'evidence'=>$evidence]),(int)$p['id']]);
            }elseif(in_array($status,['failed','cancelled'],true))db()->prepare("UPDATE prospect_prototypes SET state='blocked',qa_json=?,updated_at=NOW() WHERE id=?")->execute([j(['stage'=>'emad','status'=>$status,'summary'=>$summary]),(int)$p['id']]);return;
        }
        if((int)($p['ramy_task_id']??0)===$taskId){if($status==='completed')db()->prepare("UPDATE prospect_prototypes SET state='contacted',outreach_json=?,updated_at=NOW() WHERE id=?")->execute([j(['summary'=>$summary,'evidence'=>$evidence,'completed_at'=>now_utc()]),(int)$p['id']]);elseif(in_array($status,['failed','cancelled'],true))db()->prepare("UPDATE prospect_prototypes SET state='prototype_ready',outreach_json=?,updated_at=NOW() WHERE id=?")->execute([j(['status'=>$status,'summary'=>$summary]),(int)$p['id']]);}
    }
    public static function status(int $leadId):?array{$q=db()->prepare('SELECT p.*,pr.name project_name FROM prospect_prototypes p LEFT JOIN projects pr ON pr.id=p.project_id WHERE p.lead_id=?');$q->execute([$leadId]);return $q->fetch()?:null;}
    private static function queueRamy(array $p,string $url,array $review):int{
        $lead=self::lead((int)$p['lead_id']);$ramy=AgentService::bySlug('ramy');$desc="Prototype اجتاز مراجعة عماد للشركة ".(string)$lead['company_name'].".\nرابط النموذج: ".$url."\nراجع بيانات التواصل العامة وقرر قناة ورسالة التواصل. أي تواصل خارجي يجب أن يمر عبر External Action Gateway وسياسات القناة.\nسبب الفرصة: ".(string)$lead['opportunity_reason'];
        $tid=TaskService::create('ramy','تواصل استباقي بعد Prototype — '.(string)$lead['company_name'],$desc,(int)$p['project_id'],['job_kind'=>'agent_task','prototype_lead_id'=>(int)$p['lead_id'],'prototype_outreach'=>1,'staging_url'=>$url,'contact_methods'=>json_decode((string)($lead['contact_methods_json']??'{}'),true)?:[]],'high',true,null,'system','prototype_workflow');
        try{TeamChatService::post('system','prototype','agent','ramy','عماد اعتمد Prototype للشركة '.(string)$lead['company_name'].'. رابط المعاينة: '.$url.' — مهمة التواصل #'.$tid,'handoff',(int)$p['project_id'],$tid,['lead_id'=>(int)$p['lead_id'],'review_id'=>(int)($review['id']??0)]);}catch(Throwable){}
        return $tid;
    }
    private static function createProject(array $lead,int $opportunityId):int{
        $uid=substr(hash('sha256','prospect-prototype|'.(int)$lead['id'].'|'.microtime(true)),0,16);$name='Prototype — '.pb_substr((string)$lead['company_name'],0,160);$meta=['purpose'=>'prospect_prototype','prospect_lead_id'=>(int)$lead['id'],'source_url'=>$lead['source_url']??null,'real_company_website'=>$lead['website_url']??null,'created_at'=>now_utc()];
        db()->prepare("INSERT INTO projects(uid,opportunity_id,name,primary_domain,status,workflow_stage,hosting_presence,source,is_system_project,metadata_json) VALUES (?,?,?,NULL,'development','awaiting_execution','unknown','client_project',0,?)")->execute([$uid,$opportunityId,$name,j($meta)]);$id=(int)db()->lastInsertId();db()->prepare('UPDATE opportunities SET project_id=? WHERE id=?')->execute([$id,$opportunityId]);foreach(['ramy'=>'manage','ayman'=>'work','emad'=>'read','walid'=>'read'] as $slug=>$scope){$a=AgentService::bySlug($slug);db()->prepare('INSERT INTO agent_project_access(agent_id,project_id,access_scope) VALUES (?,?,?) ON DUPLICATE KEY UPDATE access_scope=VALUES(access_scope)')->execute([(int)$a['id'],$id,$scope]);}return $id;
    }
    private static function briefText(array $lead):string{return 'الشركة: '.(string)$lead['company_name']."\nالدولة: ".(string)($lead['country']??'')."\nالنشاط: ".(string)($lead['industry']??'')."\nحالة الموقع: ".(string)$lead['website_state']."\nسبب الفرصة: ".(string)$lead['opportunity_reason']."\nالخدمة المقترحة: ".(string)$lead['recommended_service']."\nالمصدر العام: ".(string)$lead['source_url'];}
    private static function reviewForAyman(int $taskId):?array{$q=db()->prepare('SELECT * FROM reviews WHERE task_id=? ORDER BY id DESC LIMIT 1');$q->execute([$taskId]);return $q->fetch()?:null;}
    private static function stagingUrl(int $projectId):string{try{$p=ProjectService::get($projectId);$s=StagingService::existing($p);return !empty($s['domain'])?'https://'.$s['domain'].'/':'';}catch(Throwable){return '';}}
    private static function lead(int $id):array{$q=db()->prepare('SELECT * FROM prospect_leads WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('prospect_not_found');return $r;}
}
