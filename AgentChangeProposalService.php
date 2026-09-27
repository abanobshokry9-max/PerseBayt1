<?php
declare(strict_types=1);

final class AgentChangeProposalService {
    public static function propose(int $agentId,string $type,string $title,string $summary,string $reason='',array $payload=[],string $risk='medium',?int $projectId=null,?int $sourceTaskId=null): int {
        Permissions::requireAgent($agentId,'learning.propose');
        $types=['memory','instruction','permission','tool','workflow','code_patch','schema_change','integration','test_case'];if(!in_array($type,$types,true))throw new RuntimeException('change_proposal_type_invalid');
        $risks=['low','medium','high','critical'];if(!in_array($risk,$risks,true))$risk='medium';$title=trim($title);$summary=trim($summary);if($title===''||$summary==='')throw new RuntimeException('change_proposal_fields_required');
        $q=db()->prepare("INSERT INTO agent_change_proposals(agent_id,project_id,source_task_id,proposal_type,title,summary,reason_text,risk_level,payload_json,state) VALUES (?,?,?,?,?,?,?,?,?,'pending')");
        $q->execute([$agentId,$projectId,$sourceTaskId,$type,pb_substr($title,0,220),$summary,$reason!==''?$reason:null,$risk,$payload?j($payload):null]);$id=(int)db()->lastInsertId();
        try{Notifications::add(in_array($risk,['high','critical'],true)?'warning':'info','agents','اقتراح تعلم جديد من '.AgentService::byId($agentId)['display_name'],$title.' — يحتاج موافقة المالك.','agent_change_proposal',(string)$id);}catch(Throwable){}
        Audit::log('agent',(string)$agentId,'learning.change_propose','agent_change_proposal',(string)$id,'pending',$projectId,$sourceTaskId,['type'=>$type,'risk'=>$risk]);return $id;
    }

    public static function pending(int $limit=200): array {$limit=max(1,min(500,$limit));return db()->query("SELECT p.*,a.display_name agent_name,a.slug agent_slug,pr.name project_name,t.title source_task_title FROM agent_change_proposals p JOIN agents a ON a.id=p.agent_id LEFT JOIN projects pr ON pr.id=p.project_id LEFT JOIN tasks t ON t.id=p.source_task_id ORDER BY FIELD(p.state,'pending','approved','queued','failed','implemented','rejected','cancelled'),FIELD(p.risk_level,'critical','high','medium','low'),p.id DESC LIMIT ".$limit)->fetchAll();}
    public static function get(int $id): array {$q=db()->prepare('SELECT p.*,a.slug agent_slug,a.display_name agent_name FROM agent_change_proposals p JOIN agents a ON a.id=p.agent_id WHERE p.id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('change_proposal_not_found');return $r;}

    public static function approve(int $id,string $ownerNote=''): array {
        $p=self::get($id);if((string)$p['state']!=='pending')throw new RuntimeException('change_proposal_not_pending');$payload=json_decode((string)($p['payload_json']??''),true);if(!is_array($payload))$payload=[];
        $type=(string)$p['proposal_type'];$agentId=(int)$p['agent_id'];$result=['proposal_id'=>$id,'type'=>$type];
        if(in_array($type,['memory','instruction','permission','tool'],true)){
            $before=self::snapshotDirect($agentId,$type,$payload,$p);$after=[];
            if($type==='memory'){
                $text=trim((string)($payload['text']??$p['summary']));if($text==='')throw new RuntimeException('change_proposal_payload_invalid');
                MemoryService::rememberAgent($agentId,(string)($payload['category']??'learning'),$text,'owner_approved_change',(string)$id,max(1,min(100,(int)($payload['weight']??85))));
                $q=db()->prepare("SELECT id,memory_kind,title,body_text,salience,confidence,active FROM agent_memory_bank WHERE agent_id=? AND source_type='owner_approved_change' AND source_id=? ORDER BY id DESC LIMIT 1");$q->execute([$agentId,(string)$id]);$after=['memory'=>$q->fetch()?:null];
            }elseif($type==='instruction'){
                $text=trim((string)($payload['instruction']??$p['summary']));if($text==='')throw new RuntimeException('change_proposal_payload_invalid');$a=AgentService::byId($agentId);$old=(string)$a['system_prompt'];
                $new=$old."\n\n[Owner-approved learning #".$id."]\n".$text;db()->prepare('UPDATE agents SET system_prompt=? WHERE id=?')->execute([$new,$agentId]);self::capturePrompt($agentId,$new,$id,(string)$p['title']);$after=['system_prompt'=>$new];
            }elseif($type==='permission'){
                $key=trim((string)($payload['permission_key']??''));if($key===''||!preg_match('/^[a-z0-9_.:-]{2,120}$/i',$key)||!AgentCapabilityService::permissionExists($key))throw new RuntimeException('change_proposal_permission_not_in_catalog');
                $allowed=!array_key_exists('allowed',$payload)||(bool)$payload['allowed'];
                if($allowed)AgentCapabilityService::grantPermission($agentId,$key,'owner_manual','change_proposal:'.$id);
                else self::disablePermissionSafely($agentId,$key);
                $result['permission_key']=$key;$after=self::permissionSnapshot($agentId,$key);
            }else{
                $key=trim((string)($payload['tool_key']??''));if($key===''||!preg_match('/^[a-z0-9_.:-]{2,120}$/i',$key))throw new RuntimeException('change_proposal_tool_invalid');$allowed=!array_key_exists('allowed',$payload)||(bool)$payload['allowed'];
                if($allowed)AgentCapabilityService::grantTool($agentId,$key,'owner_manual','change_proposal:'.$id,(array)($payload['config']??[]));
                else self::disableToolSafely($agentId,$key);
                $result['tool_key']=$key;$after=self::toolSnapshot($agentId,$key);
            }
            self::storeRollback($id,$agentId,$type,$before,$after);
            db()->prepare("UPDATE agent_change_proposals SET state='implemented',rollback_state='available',before_snapshot_json=?,after_snapshot_json=?,reviewed_at=NOW(),implemented_at=NOW(),owner_note=? WHERE id=?")
                ->execute([j($before),j($after),pb_substr($ownerNote,0,1000)?:null,$id]);
            Audit::log('owner',(string)(Auth::user()['id']??1),'learning.change_approve','agent_change_proposal',(string)$id,'verified',$p['project_id']?(int)$p['project_id']:null,$p['source_task_id']?(int)$p['source_task_id']:null,$result+['rollback'=>'available']);
            return $result+['state'=>'implemented','rollback_state'=>'available'];
        }

        $projectId=$p['project_id']?(int)$p['project_id']:self::companyProjectId();if(!$projectId)throw new RuntimeException('company_system_project_not_found');
        $autoPromote=!$p['project_id']&&setting('learning.auto_promote_owner_approved_system_changes','1')==='1';
        $desc="اقتراح معتمد من صفحة تعلم الوكلاء #{$id}\nالوكيل: ".(string)$p['agent_name']."\nالنوع: {$type}\nالعنوان: ".(string)$p['title']."\nالشرح: ".(string)$p['summary']."\nالسبب: ".(string)($p['reason_text']??'')."\nPayload: ".Security::redactSecrets(j($payload),6000)."\nنفذ التغيير أولًا على محطة nourmakkah.com، خذ Backup قبل أي تعديل حساس، ثم اطلب مراجعة عماد. لا تنشر إلى Production إلا بعد QA ناجح وEvidence موثق. سجّل قبل/بعد وRollback plan ضمن Evidence.";
        $task=TaskService::create('ayman','تطبيق تطوير معتمد: '.pb_substr((string)$p['title'],0,150),$desc,$projectId,['job_kind'=>'ayman_execute','approved_change_proposal'=>$id,'proposal_type'=>$type,'owner_approved'=>1,'auto_promote_after_qa'=>$autoPromote?1:0,'requires_before_after_snapshot'=>1,'requires_rollback_plan'=>1],'high',true,null,'owner',(string)(Auth::user()['id']??1));
        db()->prepare("UPDATE agent_change_proposals SET project_id=?,implementation_task_id=?,state='queued',reviewed_at=NOW(),owner_note=? WHERE id=?")->execute([$projectId,$task,pb_substr($ownerNote,0,1000)?:null,$id]);
        $result['implementation_task_id']=$task;$result['project_id']=$projectId;$result['auto_promote_after_qa']=$autoPromote;Audit::log('owner',(string)(Auth::user()['id']??1),'learning.change_approve','agent_change_proposal',(string)$id,'queued',$projectId,$task,$result);return $result+['state'=>'queued'];
    }

    public static function rollback(int $id,string $ownerNote=''):array{
        $p=self::get($id);if((string)($p['rollback_state']??'')!=='available')throw new RuntimeException('change_rollback_not_available');
        $q=db()->prepare('SELECT * FROM agent_change_rollbacks WHERE proposal_id=? LIMIT 1');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('change_rollback_snapshot_missing');
        $before=json_decode((string)($r['before_json']??'{}'),true);if(!is_array($before))$before=[];$type=(string)$r['change_type'];$agentId=(int)$r['agent_id'];
        try{
            if($type==='memory')db()->prepare("UPDATE agent_memory_bank SET active=0,updated_at=NOW() WHERE agent_id=? AND source_type='owner_approved_change' AND source_id=?")->execute([$agentId,(string)$id]);
            elseif($type==='instruction'){if(!array_key_exists('system_prompt',$before))throw new RuntimeException('rollback_prompt_snapshot_missing');db()->prepare('UPDATE agents SET system_prompt=? WHERE id=?')->execute([(string)$before['system_prompt'],$agentId]);self::capturePrompt($agentId,(string)$before['system_prompt'],$id,'Rollback: '.(string)$p['title']);}
            elseif($type==='permission')self::restorePermission($agentId,$before);
            elseif($type==='tool')self::restoreTool($agentId,$before);
            else throw new RuntimeException('change_rollback_type_not_supported');
            db()->prepare("UPDATE agent_change_rollbacks SET state='rolled_back',rolled_back_by=?,rolled_back_at=NOW(),error_text=NULL WHERE proposal_id=?")->execute([(string)(Auth::user()['id']??1),$id]);
            db()->prepare("UPDATE agent_change_proposals SET rollback_state='rolled_back',rolled_back_at=NOW(),owner_note=CONCAT(COALESCE(owner_note,''),?) WHERE id=?")->execute(["\nRollback: ".pb_substr($ownerNote,0,700),$id]);
            Audit::log('owner',(string)(Auth::user()['id']??1),'learning.change_rollback','agent_change_proposal',(string)$id,'verified',null,null,['type'=>$type]);return ['proposal_id'=>$id,'state'=>'rolled_back','type'=>$type];
        }catch(Throwable $e){$safe=pb_substr(Security::redactSecrets($e->getMessage()),0,900);db()->prepare("UPDATE agent_change_rollbacks SET state='failed',rolled_back_by=?,rolled_back_at=NOW(),error_text=? WHERE proposal_id=?")->execute([(string)(Auth::user()['id']??1),$safe,$id]);db()->prepare("UPDATE agent_change_proposals SET rollback_state='rollback_failed' WHERE id=?")->execute([$id]);throw $e;}
    }

    public static function reject(int $id,string $ownerNote=''): void {$p=self::get($id);if((string)$p['state']!=='pending')throw new RuntimeException('change_proposal_not_pending');db()->prepare("UPDATE agent_change_proposals SET state='rejected',reviewed_at=NOW(),owner_note=? WHERE id=?")->execute([pb_substr($ownerNote,0,1000)?:null,$id]);Audit::log('owner',(string)(Auth::user()['id']??1),'learning.change_reject','agent_change_proposal',(string)$id,'rejected');}

    private static function snapshotDirect(int $agentId,string $type,array $payload,array $proposal):array{
        if($type==='instruction')return ['system_prompt'=>(string)AgentService::byId($agentId)['system_prompt']];
        if($type==='permission'){ $key=trim((string)($payload['permission_key']??''));if($key===''||!AgentCapabilityService::permissionExists($key))throw new RuntimeException('change_proposal_permission_not_in_catalog');return self::permissionSnapshot($agentId,$key);}
        if($type==='tool'){ $key=trim((string)($payload['tool_key']??''));if($key==='')throw new RuntimeException('change_proposal_tool_invalid');return self::toolSnapshot($agentId,$key);}
        return ['source_type'=>'owner_approved_change','source_id'=>(string)$proposal['id'],'existing'=>false];
    }
    private static function permissionSnapshot(int $agentId,string $key):array{$q=db()->prepare('SELECT allowed FROM agent_permissions WHERE agent_id=? AND permission_key=?');$q->execute([$agentId,$key]);$allowed=$q->fetchColumn();$s=db()->prepare('SELECT source_type,source_key FROM agent_permission_sources WHERE agent_id=? AND permission_key=? ORDER BY source_type,source_key');$s->execute([$agentId,$key]);return ['permission_key'=>$key,'exists'=>$allowed!==false,'allowed'=>$allowed===false?null:(int)$allowed,'sources'=>$s->fetchAll()];}
    private static function toolSnapshot(int $agentId,string $key):array{$q=db()->prepare('SELECT allowed,config_json FROM agent_tools WHERE agent_id=? AND tool_key=?');$q->execute([$agentId,$key]);$row=$q->fetch();$s=db()->prepare('SELECT source_type,source_key FROM agent_tool_sources WHERE agent_id=? AND tool_key=? ORDER BY source_type,source_key');$s->execute([$agentId,$key]);return ['tool_key'=>$key,'exists'=>(bool)$row,'allowed'=>$row?(int)$row['allowed']:null,'config_json'=>$row?(string)$row['config_json']:null,'sources'=>$s->fetchAll()];}
    private static function disablePermissionSafely(int $agentId,string $key):void{$q=db()->prepare("SELECT COUNT(*) FROM agent_permission_sources WHERE agent_id=? AND permission_key=? AND source_type IN ('capability','role_template','system')");$q->execute([$agentId,$key]);if((int)$q->fetchColumn()>0)throw new RuntimeException('permission_revoke_requires_capability_or_role_change');db()->prepare('UPDATE agent_permissions SET allowed=0 WHERE agent_id=? AND permission_key=?')->execute([$agentId,$key]);}
    private static function disableToolSafely(int $agentId,string $key):void{$q=db()->prepare("SELECT COUNT(*) FROM agent_tool_sources WHERE agent_id=? AND tool_key=? AND source_type IN ('capability','role_template','system')");$q->execute([$agentId,$key]);if((int)$q->fetchColumn()>0)throw new RuntimeException('tool_revoke_requires_capability_or_role_change');db()->prepare('UPDATE agent_tools SET allowed=0 WHERE agent_id=? AND tool_key=?')->execute([$agentId,$key]);}
    private static function restorePermission(int $agentId,array $before):void{$key=(string)($before['permission_key']??'');if($key==='')throw new RuntimeException('rollback_permission_snapshot_missing');db()->prepare('DELETE FROM agent_permission_sources WHERE agent_id=? AND permission_key=?')->execute([$agentId,$key]);foreach((array)($before['sources']??[]) as $s){db()->prepare('INSERT IGNORE INTO agent_permission_sources(agent_id,permission_key,source_type,source_key) VALUES (?,?,?,?)')->execute([$agentId,$key,(string)$s['source_type'],(string)$s['source_key']]);}if(empty($before['exists']))db()->prepare('DELETE FROM agent_permissions WHERE agent_id=? AND permission_key=?')->execute([$agentId,$key]);else db()->prepare('INSERT INTO agent_permissions(agent_id,permission_key,allowed) VALUES (?,?,?) ON DUPLICATE KEY UPDATE allowed=VALUES(allowed)')->execute([$agentId,$key,(int)($before['allowed']??0)]);}
    private static function restoreTool(int $agentId,array $before):void{$key=(string)($before['tool_key']??'');if($key==='')throw new RuntimeException('rollback_tool_snapshot_missing');db()->prepare('DELETE FROM agent_tool_sources WHERE agent_id=? AND tool_key=?')->execute([$agentId,$key]);foreach((array)($before['sources']??[]) as $s){db()->prepare('INSERT IGNORE INTO agent_tool_sources(agent_id,tool_key,source_type,source_key) VALUES (?,?,?,?)')->execute([$agentId,$key,(string)$s['source_type'],(string)$s['source_key']]);}if(empty($before['exists']))db()->prepare('DELETE FROM agent_tools WHERE agent_id=? AND tool_key=?')->execute([$agentId,$key]);else db()->prepare('INSERT INTO agent_tools(agent_id,tool_key,allowed,config_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE allowed=VALUES(allowed),config_json=VALUES(config_json)')->execute([$agentId,$key,(int)($before['allowed']??0),(string)($before['config_json']??'{}')]);}
    private static function storeRollback(int $id,int $agentId,string $type,array $before,array $after):void{db()->prepare("INSERT INTO agent_change_rollbacks(proposal_id,agent_id,change_type,before_json,after_json,state) VALUES (?,?,?,?,?,'available') ON DUPLICATE KEY UPDATE before_json=VALUES(before_json),after_json=VALUES(after_json),state='available',error_text=NULL")->execute([$id,$agentId,$type,j($before),j($after)]);}
    private static function companyProjectId(): int {$host=pb_strtolower((string)(parse_url((string)config('app.base_url','https://persebayt.com'),PHP_URL_HOST)?:'persebayt.com'));$q=db()->prepare("SELECT id FROM projects WHERE is_system_project=1 AND LOWER(primary_domain)=LOWER(?) ORDER BY id ASC LIMIT 1");$q->execute([$host]);$id=(int)($q->fetchColumn()?:0);if($id)return $id;$q=db()->query("SELECT id FROM projects WHERE is_system_project=1 AND source='system' ORDER BY id ASC LIMIT 1");return (int)($q->fetchColumn()?:0);}
    private static function capturePrompt(int $agentId,string $prompt,int $proposalId,string $summary): void {$q=db()->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM agent_prompt_versions WHERE agent_id=?');$q->execute([$agentId]);$v=(int)$q->fetchColumn();db()->prepare('INSERT INTO agent_prompt_versions(agent_id,proposal_id,version_no,prompt_text,change_summary,created_by_type,created_by_id) VALUES (?,?,?,?,?,?,?)')->execute([$agentId,$proposalId,max(1,$v),$prompt,pb_substr($summary,0,1000),'owner',(string)(Auth::user()['id']??1)]);}
}
