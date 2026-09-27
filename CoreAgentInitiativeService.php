<?php
declare(strict_types=1);
final class CoreAgentInitiativeService {
    public static function execute(array $agent,string $action,array $payload=[]):array{return match($action){
        'review_pipeline'=>self::reviewPipeline($agent),
        'prepare_owner_brief'=>self::ownerBrief($agent),
        'engineering_review'=>self::engineeringReview($agent),
        'qa_security_review'=>self::qaReview($agent),
        'social_operations_review'=>self::socialReview($agent),
        'media_queue_review'=>self::mediaReview($agent),
        'creator_review'=>self::creatorReview($agent),
        'community_review'=>self::communityReview($agent),
        'free_model_review'=>self::freeModelReview($agent),
        'role_review'=>self::roleReview($agent),
        default=>throw new RuntimeException('specialized_initiative_unknown:'.$action)
    };}
    private static function count(string $sql,array $args=[]):int{try{$q=db()->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();}catch(Throwable){return 0;}}
    private static function team(string $slug,string $body,?int $projectId=null):void{try{TeamChatService::post('agent',$slug,'team',pb_substr($body,0,900),'status',$projectId,null,['autonomy'=>1]);}catch(Throwable){}}
    private static function reviewPipeline(array $a):array{
        $r=['open_opportunities'=>self::count("SELECT COUNT(*) FROM opportunities WHERE status NOT IN ('rejected','closed','won','lost')"),'active_projects'=>self::count("SELECT COUNT(*) FROM projects WHERE status NOT IN ('archived')"),'blocked_tasks'=>self::count("SELECT COUNT(*) FROM tasks WHERE status='blocked'"),'failed_jobs_24h'=>self::count("SELECT COUNT(*) FROM jobs WHERE state='failed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)"),'actual_metric_delta'=>0];
        $r['message']='راجعت خط التشغيل: '.$r['blocked_tasks'].' مهمة متوقفة، '.$r['failed_jobs_24h'].' وظيفة فاشلة خلال ٢٤ ساعة، و'.$r['active_projects'].' مشروعًا نشطًا.';if($r['blocked_tasks']||$r['failed_jobs_24h'])self::team((string)$a['slug'],$r['message'].' سأوجّه التشخيص لصاحب الخطوة التالية بدل ترك المسار صامتًا.');return $r;
    }
    private static function ownerBrief(array $a):array{$s=self::reviewPipeline($a);$s['goals_at_risk']=self::count("SELECT COUNT(*) FROM company_goals WHERE state='active' AND health_state IN ('at_risk','behind')");$text='ملخص رامي: فرص مفتوحة '.$s['open_opportunities'].'، مشاريع نشطة '.$s['active_projects'].'، مهام متوقفة '.$s['blocked_tasks'].'، وظائف فاشلة آخر ٢٤ ساعة '.$s['failed_jobs_24h'].'، أهداف معرضة للخطر '.$s['goals_at_risk'].'.';Notifications::add('info','management','ملخص رامي التنفيذي',$text,'agent',(string)$a['id']);return ['message'=>$text,'snapshot'=>$s,'actual_metric_delta'=>0];}
    private static function engineeringReview(array $a):array{
        $q=db()->prepare("SELECT t.id,t.title,t.status,t.project_id,t.updated_at,p.name project_name FROM tasks t LEFT JOIN projects p ON p.id=t.project_id WHERE t.assigned_agent_id=? AND t.status IN ('blocked','needs_review','needs_fix','retesting','queued','assigned','working') ORDER BY FIELD(t.status,'blocked','needs_fix','needs_review','retesting','working','queued','assigned'),t.updated_at ASC LIMIT 12");$q->execute([(int)$a['id']]);$queue=$q->fetchAll();
        $f=db()->prepare("SELECT j.id,j.task_id,j.project_id,j.error_code,j.updated_at,t.title task_title FROM jobs j LEFT JOIN tasks t ON t.id=j.task_id WHERE j.agent_id=? AND j.state='failed' AND j.updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) ORDER BY j.id DESC LIMIT 5");$f->execute([(int)$a['id']]);$failed=$f->fetchAll();
        $message='راجعت تنفيذ أيمن: '.count($queue).' مهمة نشطة/معلقة و'.count($failed).' فشل حديث.';if($failed){$last=$failed[0];$message.=' آخر سبب: '.AdminUi::humanError((string)($last['error_code']??'')).'. سأبقي عماد للمراجعة فقط بعد نجاح التنفيذ.';self::team((string)$a['slug'],$message,$last['project_id']?(int)$last['project_id']:null);}
        return ['queue'=>$queue,'recent_failures'=>$failed,'message'=>$message,'actual_metric_delta'=>0];
    }
    private static function qaReview(array $a):array{
        $created=0;try{$created=Workflow::reconcilePendingReviews(12);}catch(Throwable){}
        $ready=self::count("SELECT COUNT(*) FROM security_findings WHERE status='ready_retest'");$open=self::count("SELECT COUNT(*) FROM security_findings WHERE status IN ('open','reopened','assigned','fixing')");$pending=self::count("SELECT COUNT(*) FROM tasks t JOIN agents x ON x.id=t.assigned_agent_id WHERE x.slug='emad' AND t.status IN ('queued','assigned','working','waiting','retesting')");
        $message='راجعت طابور عماد: '.$pending.' مراجعات نشطة، '.$ready.' جاهزة لإعادة الاختبار، وأنشأت '.$created.' مراجعات ناقصة من أعمال أيمن الجاهزة.';if($created||$ready||$pending)self::team((string)$a['slug'],$message);return ['created_reviews'=>$created,'pending_reviews'=>$pending,'ready_retests'=>$ready,'open_findings'=>$open,'message'=>$message,'actual_metric_delta'=>0];
    }
    private static function socialReview(array $a):array{return ['failed_publications_7d'=>self::count("SELECT COUNT(*) FROM social_publications WHERE state='failed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)"),'pending_playbooks'=>self::count("SELECT COUNT(*) FROM social_account_runs WHERE state IN ('queued','running','challenge')"),'actual_metric_delta'=>0];}
    private static function mediaReview(array $a):array{return ['failed_scenes'=>self::count("SELECT COUNT(*) FROM video_scenes WHERE state='failed'"),'pending_media'=>self::count("SELECT COUNT(*) FROM media_requests WHERE state IN ('requested','submitted','processing')"),'actual_metric_delta'=>0];}
    private static function creatorReview(array $a):array{$r=CreatorIntelligenceService::refreshAll(1200);$attention=CreatorIntelligenceService::attention(20);return ['refresh'=>$r,'attention'=>$attention,'actual_metric_delta'=>0];}
    private static function communityReview(array $a):array{
        $r=['open_customer_conversations'=>self::count("SELECT COUNT(*) FROM conversation_sessions WHERE subject_type='customer' AND status='open'"),'whatsapp_pending'=>self::count("SELECT COUNT(*) FROM whatsapp_pending_messages WHERE state IN ('pending','failed') AND sent_at IS NULL"),'community_open_tasks'=>self::count("SELECT COUNT(*) FROM tasks WHERE assigned_agent_id=? AND status IN ('assigned','queued','working','waiting','blocked','needs_fix')",[(int)$a['id']]),'actual_metric_delta'=>0];
        $r['message']='مراجعة المجتمع: '.$r['open_customer_conversations'].' محادثة عميل مفتوحة، '.$r['whatsapp_pending'].' رسالة واتساب معلقة، و'.$r['community_open_tasks'].' مهمة مجتمع تحتاج متابعة.';if($r['whatsapp_pending']||$r['community_open_tasks'])self::team((string)$a['slug'],$r['message']);return $r;
    }
    private static function freeModelReview(array $a):array{
        $r=['working_models'=>self::count("SELECT COUNT(*) FROM free_model_catalog WHERE active=1 AND health_state='working'"),'available_models'=>self::count("SELECT COUNT(*) FROM free_model_catalog WHERE active=1 AND health_state IN ('working','available','untested')"),'failed_providers'=>self::count("SELECT COUNT(*) FROM free_provider_candidates WHERE state='failed'"),'human_required'=>self::count("SELECT COUNT(*) FROM free_provider_candidates WHERE state='human_required'"),'actual_metric_delta'=>0];
        $r['message']='مراجعة نور: '.$r['working_models'].' موديل متحقق، '.$r['available_models'].' موديل متاح/قيد الاختبار، '.$r['failed_providers'].' مزود متعطل، و'.$r['human_required'].' مزود يحتاج إجراء من المالك.';if($r['failed_providers']||$r['human_required'])self::team((string)$a['slug'],$r['message']);return $r;
    }
    private static function roleReview(array $a):array{return ['open_tasks'=>self::count("SELECT COUNT(*) FROM tasks WHERE assigned_agent_id=? AND status IN ('assigned','queued','working','blocked','waiting','needs_review')",[(int)$a['id']]),'open_followups'=>self::count("SELECT COUNT(*) FROM agent_followups WHERE agent_id=? AND state IN ('scheduled','queued')",[(int)$a['id']]),'actual_metric_delta'=>0];}
}
