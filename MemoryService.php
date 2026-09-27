<?php
declare(strict_types=1);
final class MemoryService {
    public static function assertSafeMemory(string $body):void{
        $x=trim($body);if($x==='')return;
        $patterns=[
            '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i',
            '/\bsk-[A-Za-z0-9_-]{16,}\b/',
            '/\bEAA[A-Za-z0-9]{20,}\b/',
            '/\beyJ[A-Za-z0-9_-]{15,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{20,}/i',
            '/\b(?:password|passwd|secret|token|api[_ -]?key|access[_ -]?token)\s*[:=]\s*[^\s]{8,}/i'
        ];
        foreach($patterns as $rx)if(preg_match($rx,$x))throw new RuntimeException('memory_contains_secret');
    }
    private static function norm(string $s):string{$s=trim(preg_replace('/\s+/u',' ',$s));return pb_strtolower($s);}
    public static function rememberAgent(int $agentId,string $type,string $body,string $sourceType='system',string $sourceId='',int $importance=50,?int $projectId=null):void{
        $body=trim($body);if($body==='')return;self::assertSafeMemory($body);
        $map=['core'=>'semantic','experience'=>'episodic','project'=>'episodic','owner_preference'=>'semantic','relationship'=>'entity','procedure'=>'procedural','quality_rule'=>'procedural','search_preference'=>'feedback'];
        $kind=$map[$type]??'episodic';$title=str_replace('_',' ',trim($type)).' memory';
        AgentBrainService::remember($agentId,$kind,$title,$body,$projectId,['legacy_type'=>$type],max(1,min(100,$importance)),85,null,$sourceType,$sourceId?:null);
    }
    public static function rememberProject(int $projectId,string $category,string $body,string $sourceType='system',string $sourceId=''):void{$body=trim($body);if($body==='')return;self::assertSafeMemory($body);$hash=hash('sha256',self::norm($body));db()->prepare('INSERT INTO project_memory(project_id,category,body_text,source_type,source_id,content_hash) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE active=1,updated_at=NOW()')->execute([$projectId,$category,$body,$sourceType,$sourceId,$hash]);}
    public static function agentContext(int $agentId,?int $projectId=null,int $limit=24):array{
        $rows=AgentBrainService::context($agentId,$projectId,'',max(5,min(30,$limit)));$out=[];foreach($rows as $r)$out[]=['memory_type'=>$r['kind'],'body_text'=>$r['body'],'importance'=>$r['salience'],'title'=>$r['title'],'source'=>'agent_memory_bank'];return $out;
    }
    public static function company(int $limit=30):array{return db()->query('SELECT category,body_text FROM company_memory WHERE active=1 ORDER BY updated_at DESC LIMIT '.max(5,min(50,$limit)))->fetchAll();}
    public static function project(int $projectId,int $limit=30):array{$q=db()->prepare('SELECT category,body_text FROM project_memory WHERE project_id=? AND active=1 ORDER BY updated_at DESC LIMIT '.max(5,min(50,$limit)));$q->execute([$projectId]);return $q->fetchAll();}
    public static function learnFromTask(int $taskId,string $lesson,string $type='experience'):void{
        $q=db()->prepare('SELECT assigned_agent_id,project_id FROM tasks WHERE id=?');$q->execute([$taskId]);$t=$q->fetch();if(!$t||!$t['assigned_agent_id'])return;if(!AgentService::tool((int)$t['assigned_agent_id'],'memory'))return;
        try{self::assertSafeMemory($lesson);$projectId=$t['project_id']?(int)$t['project_id']:null;if($projectId)self::rememberProject($projectId,'lesson',$lesson,'task',(string)$taskId);if(setting('learning.owner_review_required','1')==='1'){AgentLearningService::propose((int)$t['assigned_agent_id'],$type,$lesson,$projectId,$taskId,70);}else self::rememberAgent((int)$t['assigned_agent_id'],$type,$lesson,'task',(string)$taskId,70,$projectId);}catch(Throwable $e){$actor=Audit::actor();Audit::log((string)$actor['type'],(string)$actor['id'],'memory.lesson_skipped','task',(string)$taskId,'blocked',$t['project_id']?(int)$t['project_id']:null,$taskId,['reason'=>pb_substr(Security::redactSecrets($e->getMessage()),0,200)]);error_log('ELMETR task learning skipped: '.Security::redactSecrets($e->getMessage()));}
    }
    public static function maybeRememberOwnerPreference(int $sessionId,string $text):void{
        $text=trim($text);if($text===''||pb_strlen($text)>2400)return;
        // Only spend a classification call when the owner is explicitly expressing a lasting preference/rule.
        if(!preg_match('/(?:مش عايز|ما\s*تعملش|متعملش|لا\s+(?:تكتب|تسأل|تبعت|ترسل|تكرر)|عايزك\s+(?:دايم|دائم|من دلوقتي)|من\s+(?:دلوقتي|الآن)|افتكر|تذكر|خلي\s+.*(?:دايم|دائم)|أفضل|افضل)/u',$text))return;
        try{
            $ramy=AgentService::bySlug('ramy');if(!AgentService::runnable($ramy)||!AgentService::tool((int)$ramy['id'],'memory'))return;$ctx=ConversationService::activeContext($sessionId);
            $schema=['type'=>'object','additionalProperties'=>false,'properties'=>[
                'remember'=>['type'=>'boolean'],'scope'=>['type'=>'string','enum'=>['ramy','company','project']],
                'memory'=>['type'=>'string'],'importance'=>['type'=>'integer','minimum'=>1,'maximum'=>100]
            ],'required'=>['remember','scope','memory','importance']];
            $instructions='استخرج فقط تفضيلًا أو قاعدة عمل ثابتة قالها المالك صراحة. لا تحفظ أوامر مشروع مؤقتة، ولا أسرار أو كلمات مرور أو tokens أو أرقام حساسة. إذا ليست قاعدة مستمرة اجعل remember=false. اكتب الذاكرة بصياغة قصيرة وواضحة بالعربية.';
            $r=AiGateway::json($ramy,$instructions,[['role'=>'user','content'=>$text]],$schema,'owner_preference',900);$d=$r['data'];if(empty($d['remember'])||trim((string)$d['memory'])==='')return;
            $memory=trim((string)$d['memory']);self::assertSafeMemory($memory);$scope=(string)$d['scope'];$importance=max(40,min(100,(int)$d['importance']));
            self::rememberAgent((int)$ramy['id'],'owner_preference',$memory,'owner_message','session:'.$sessionId,$importance,$scope==='project'&&!empty($ctx['project']['id'])?(int)$ctx['project']['id']:null);
            if($scope==='company'){
                $hash=hash('sha256',self::norm($memory));db()->prepare("INSERT INTO company_memory(category,body_text,source,content_hash,active) VALUES ('owner_preferences',?,'owner_message',?,1) ON DUPLICATE KEY UPDATE body_text=VALUES(body_text),active=1,updated_at=NOW()")->execute([$memory,$hash]);
            }elseif($scope==='project'&&!empty($ctx['project']['id']))self::rememberProject((int)$ctx['project']['id'],'owner_preference',$memory,'owner_message','session:'.$sessionId);
            Audit::log('agent',(string)$ramy['id'],'memory.owner_preference','conversation_session',(string)$sessionId,'verified',$ctx['project']['id']??null,null,['scope'=>$scope,'importance'=>$importance]);
        }catch(Throwable $e){error_log('ELMETR memory preference: '.Security::redactSecrets($e->getMessage()));}
    }

}
