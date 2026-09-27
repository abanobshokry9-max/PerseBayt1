<?php
declare(strict_types=1);
final class GenericAgentRunner {
    public static function run(int $taskId):array{
        $t=TaskService::start($taskId);$agent=AgentService::assertRunnable(AgentService::byId((int)$t['assigned_agent_id']));AgentService::requireTool((int)$agent['id'],'task_context');$project=null;if($t['project_id']){Permissions::requireProject((int)$agent['id'],(int)$t['project_id'],'read');$project=ProjectService::get((int)$t['project_id']);}
        $mem=AgentService::tool((int)$agent['id'],'memory')?['company'=>MemoryService::company(18),'agent'=>MemoryService::agentContext((int)$agent['id'],$t['project_id']?(int)$t['project_id']:null,24),'project'=>$t['project_id']?MemoryService::project((int)$t['project_id'],20):[]]:['company'=>[],'agent'=>[],'project'=>[]];
        $agentData=AgentDataService::contextForAgent((int)$agent['id'],$t['project_id']?(int)$t['project_id']:null,30);
        $specialContext=[];
        if(AgentService::tool((int)$agent['id'],'agency_ops')){try{$agencyRows=AgencyService::creatorRows(null,160);usort($agencyRows,static fn($x,$y)=>(int)($y['attention']['score']??0)<=>(int)($x['attention']['score']??0));$specialContext['agency_ops']=['summary'=>AgencyService::summary(),'priority_creators'=>array_slice($agencyRows,0,35),'supervisors'=>array_values(array_filter(ContactDirectoryService::all(),static fn($c)=>(string)($c['group_key']??'')==='elfares_supervisors'))];}catch(Throwable $e){$specialContext['agency_ops']=['error'=>pb_substr(Security::redactSecrets($e->getMessage()),0,180)];}}

        $schema=['type'=>'object','additionalProperties'=>false,'properties'=>[
            'status'=>['type'=>'string','enum'=>['completed','blocked']],
            'summary'=>['type'=>'string'],
            'evidence'=>['type'=>'array','items'=>['type'=>'string']],
            'actions'=>AgentActionService::schema(),
            'needs_manager'=>['type'=>'boolean'],
            'manager_note'=>['type'=>'string']
        ],'required'=>['status','summary','evidence','actions','needs_manager','manager_note']];
        $actionGuide="\nأدوات التنفيذ الآلي المتاحة عند امتلاك الصلاحية: learning.propose، learning.change_proposal، followup.schedule، team.message، owner.message، data.ensure_schema، data.add_row، social.contact_upsert، social.content_draft، video.generate، browser.execute، account.create. استخدم learning.change_proposal لأي تحسين في التعليمات/الصلاحيات/الأدوات/الكود/الجداول؛ لا تعدلها بنفسك. team.message يسمح بطلب عمل من وكيل آخر داخل غرفة الفريق. owner.message للوحة المالك فقط. account.create وعمليات browser الخارجية تحتاج approved_actions. عمليات النشر/الإرسال telegram.send وtiktok.publish وyoutube.upload وwhatsapp.send وfacebook.publish وinstagram.publish وsocial.publish لا تنفذ إلا إذا المهمة نفسها تحمل approved_actions صريحة من رامي/المالك. أعد actions كمصفوفة؛ payload_json يجب أن يكون JSON صحيحًا. لا تطلب تعديل System Prompt ذاتيًا؛ التعلم الآمن يذهب للذاكرة، والتعليمات الثابتة يراجعها رامي/المالك.";
        $r=AiGateway::json($agent,(string)$agent['system_prompt']."\nنفذ فقط ما تسمح به أدواتك وصلاحياتك. لا تدّع استخدام أداة أو تنفيذًا لم يحدث. إذا كانت المهمة تحتاج أداة غير متاحة اجعل status=blocked واشرح المطلوب لرامي.".$actionGuide,[['role'=>'user','content'=>"TASK:\n".$t['description']."\nTASK_CONTEXT:\n".(string)($t['context_json']??'{}')."\nPROJECT:\n".j($project?:[])."\nMEMORY:\n".j($mem)."\nAGENT_DATA_STUDIO:\n".j($agentData)."\nSPECIAL_CONTEXT:\n".j($specialContext)]],$schema,'generic_agent_task',5200);
        $o=$r['data'];$evidence=array_values(array_map('strval',(array)($o['evidence']??[])));$actionResults=[];$blockedAction='';
        foreach((array)($o['actions']??[]) as $action){
            if(!is_array($action))continue;TaskService::assertContinuable($taskId);AgentService::assertRunnable($agent);
            try{$res=AgentActionService::execute($agent,$t,$action);$actionResults[]=$res;if(!empty($res['evidence']))$evidence[]=(string)$res['evidence'];}
            catch(Throwable $e){$blockedAction=Security::redactSecrets($e->getMessage(),220);$actionResults[]=['type'=>$action['type']??'unknown','error'=>$blockedAction];break;}
        }
        if($blockedAction!==''){$o['status']='blocked';$o['needs_manager']=true;$o['manager_note']='توقف تنفيذ إجراء فعلي: '.$blockedAction;}
        TaskService::assertContinuable($taskId);AgentService::assertRunnable($agent);db()->prepare('INSERT INTO agent_runs(agent_id,task_id,state,provider_key,model,input_tokens,output_tokens,summary,completed_at) VALUES (?,?,?,?,?,?,?,?,NOW())')->execute([$agent['id'],$taskId,$o['status']==='completed'?'completed':'blocked',$agent['provider_key'],$r['model'],$r['input_tokens'],$r['output_tokens'],pb_substr((string)$o['summary'],0,1000)]);
        $meta=['evidence'=>$evidence,'actions'=>$actionResults,'manager_note'=>$o['manager_note']];
        if($o['status']==='blocked'){db()->prepare("UPDATE tasks SET status='blocked' WHERE id=?")->execute([$taskId]);TaskService::event($taskId,'agent',(string)$agent['id'],'blocked','working','blocked',['summary'=>$o['summary'],'manager_note'=>$o['manager_note']],$meta);try{AgentFollowupService::resolveFromTask($taskId,'blocked',(string)($o['manager_note']?:$o['summary']),$meta);}catch(Throwable){}try{ProspectPrototypeService::afterTask($taskId,'blocked',$meta,(string)$o['summary']);}catch(Throwable){}AgentService::runtimeStatus((int)$agent['id'],'idle');Notifications::add('warning','tasks','مهمة تحتاج تدخل رامي',$o['manager_note']?:$o['summary'],'task',(string)$taskId);return $o+['action_results'=>$actionResults,'evidence'=>$evidence];}
        TaskService::complete($taskId,$meta,$o['summary']);return $o+['action_results'=>$actionResults,'evidence'=>$evidence];
    }
}
