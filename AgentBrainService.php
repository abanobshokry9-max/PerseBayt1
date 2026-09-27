<?php
declare(strict_types=1);

final class AgentBrainService {
    private const KINDS=['episodic','semantic','procedural','entity','goal','feedback'];

    public static function remember(int $agentId,string $kind,string $title,string $body,?int $projectId=null,array $meta=[],int $salience=60,int $confidence=80,?string $entityKey=null,?string $sourceType=null,?string $sourceId=null):int{
        AgentService::byId($agentId);$kind=in_array($kind,self::KINDS,true)?$kind:'episodic';$title=trim($title);$body=trim($body);if($title===''||$body==='')throw new RuntimeException('brain_memory_required');MemoryService::assertSafeMemory($body);
        $salience=max(1,min(100,$salience));$confidence=max(1,min(100,$confidence));$embedding=EmbeddingService::embed($title."\n".$body,$agentId);
        $q=db()->prepare("INSERT INTO agent_memory_bank(agent_id,project_id,memory_kind,title,body_text,entity_key,salience,confidence,source_type,source_id,occurred_at,embedding_json,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),?,?)");
        $q->execute([$agentId,$projectId,$kind,pb_substr($title,0,240),pb_substr($body,0,30000),$entityKey?pb_substr($entityKey,0,240):null,$salience,$confidence,$sourceType?pb_substr($sourceType,0,60):null,$sourceId?pb_substr($sourceId,0,190):null,$embedding?j($embedding):null,$meta?j($meta):null]);
        $id=(int)db()->lastInsertId();$sup=(int)($meta['supersedes_memory_id']??0);if($sup>0){self::link($sup,$id,'supersedes',1.0);db()->prepare('UPDATE agent_memory_bank SET active=0,metadata_json=? WHERE id=? AND agent_id=?')->execute([j(['superseded_by'=>$id,'superseded_at'=>now_utc()]),$sup,$agentId]);}Audit::log('agent',(string)$agentId,'brain.remember','agent_memory_bank',(string)$id,'verified',$projectId,null,['kind'=>$kind,'salience'=>$salience]);return $id;
    }

    public static function context(int $agentId,?int $projectId=null,string $query='',int $limit=12):array{
        $limit=max(1,min(30,$limit));$candidateLimit=max(180,min(500,$limit*20));$args=[$agentId];$where="agent_id=? AND active=1 AND (expires_at IS NULL OR expires_at>NOW())";
        if($projectId){$where.=' AND (project_id IS NULL OR project_id=?)';$args[]=$projectId;}
        $q=db()->prepare("SELECT * FROM agent_memory_bank WHERE $where ORDER BY salience DESC,COALESCE(last_used_at,created_at) DESC LIMIT ".$candidateLimit);$q->execute($args);$rows=$q->fetchAll();
        // Hybrid retrieval: add exact entity/title/body keyword candidates even if they are old and no longer highly salient.
        $terms=self::terms($query);if($terms){$conds=[];$kargs=[$agentId];if($projectId){$projectClause=' AND (project_id IS NULL OR project_id=?)';$kargs[]=$projectId;}else $projectClause='';foreach(array_slice($terms,0,5) as $t){$conds[]='(LOWER(title) LIKE ? OR LOWER(body_text) LIKE ? OR LOWER(COALESCE(entity_key,\'\')) LIKE ?)';$like='%'.pb_strtolower($t).'%';array_push($kargs,$like,$like,$like);}if($conds){$kq=db()->prepare("SELECT * FROM agent_memory_bank WHERE agent_id=? AND active=1 AND (expires_at IS NULL OR expires_at>NOW()){$projectClause} AND (".implode(' OR ',$conds).") ORDER BY updated_at DESC LIMIT 120");$kq->execute($kargs);$seen=[];foreach($rows as $r)$seen[(int)$r['id']]=1;foreach($kq->fetchAll() as $r)if(!isset($seen[(int)$r['id']])){$rows[]=$r;$seen[(int)$r['id']]=1;}}}
        if(!$rows)return [];$needle=EmbeddingService::embed($query,$agentId);
        foreach($rows as &$r){$score=((int)$r['salience'])/100.0*0.35+((int)$r['confidence'])/100.0*0.10;$vec=json_decode((string)($r['embedding_json']??''),true);if($needle&&is_array($vec))$score+=max(0,EmbeddingService::cosine($needle,$vec))*0.45;$hay=pb_strtolower((string)$r['title'].' '.(string)$r['body_text'].' '.(string)($r['entity_key']??''));$hit=0;foreach($terms as $t)if($t!==''&&str_contains($hay,$t))$hit++;if($terms)$score+=min(0.25,$hit/max(1,count($terms))*0.25);if($projectId&&(int)($r['project_id']??0)===$projectId)$score+=0.08;$r['_score']=$score;}$r=null;
        usort($rows,static fn($a,$b)=>($b['_score']<=>$a['_score']));$rows=array_slice($rows,0,$limit);$ids=array_map(static fn($r)=>(int)$r['id'],$rows);if($ids){db()->exec('UPDATE agent_memory_bank SET access_count=access_count+1,last_used_at=NOW() WHERE id IN ('.implode(',',$ids).')');}
        return array_map(static function($r){return ['id'=>(int)$r['id'],'kind'=>$r['memory_kind'],'title'=>$r['title'],'body'=>$r['body_text'],'entity_key'=>$r['entity_key'],'salience'=>(int)$r['salience'],'confidence'=>(int)$r['confidence'],'score'=>round((float)$r['_score'],4),'project_id'=>$r['project_id']?(int)$r['project_id']:null,'created_at'=>$r['created_at']];},$rows);
    }

    public static function consolidateAgent(int $agentId,int $maxItems=20):array{
        if(setting('agents.brain_enabled','1')==='0')return ['state'=>'disabled'];$agent=AgentService::byId($agentId);$maxItems=max(5,min(40,$maxItems));
        $q=db()->prepare("SELECT * FROM agent_memory_bank WHERE agent_id=? AND active=1 AND consolidated_into_id IS NULL AND memory_kind IN ('episodic','feedback') ORDER BY salience DESC,created_at ASC LIMIT ".$maxItems);$q->execute([$agentId]);$rows=$q->fetchAll();if(count($rows)<4)return ['state'=>'skip','reason'=>'not_enough_memories','count'=>count($rows)];
        $schema=['type'=>'object','additionalProperties'=>false,'properties'=>['title'=>['type'=>'string'],'semantic'=>['type'=>'string'],'procedure'=>['type'=>'string'],'entity_key'=>['type'=>'string'],'confidence'=>['type'=>'integer']],'required'=>['title','semantic','procedure','entity_key','confidence']];
        $input=array_map(static fn($r)=>['id'=>(int)$r['id'],'kind'=>$r['memory_kind'],'title'=>$r['title'],'body'=>$r['body_text'],'salience'=>(int)$r['salience']],$rows);
        try{$res=AiGateway::json($agent,'لخص خبرات الموظف إلى معرفة طويلة المدى بدون أسرار. استخرج حقيقة/نمطًا دلاليًا وإجراءً عمليًا قابلًا لإعادة الاستخدام. لا تخترع معلومات غير موجودة.',[['role'=>'user','content'=>j($input)]],$schema,'memory_consolidation',1600);$d=(array)$res['data'];}
        catch(Throwable $e){$d=['title'=>'خلاصة خبرات متكررة','semantic'=>implode(' | ',array_map(static fn($r)=>pb_substr((string)$r['body_text'],0,300),$rows)),'procedure'=>'راجع هذه الخبرات عند موقف مشابه وتحقق من الأدلة قبل تكرار القرار.','entity_key'=>'','confidence'=>70];}
        $projects=array_values(array_unique(array_map(static fn($r)=>(int)($r['project_id']??0),$rows)));$nonZero=array_values(array_filter($projects));$summaryProject=count($nonZero)===1&&count($projects)===1?$nonZero[0]:null;$scope=$summaryProject?'project':'general';
        $semanticId=self::remember($agentId,'semantic',(string)$d['title'],(string)$d['semantic'],$summaryProject,['consolidated_from'=>array_column($rows,'id'),'scope'=>$scope],75,max(40,min(100,(int)$d['confidence'])),trim((string)$d['entity_key'])?:null,'consolidation',null);
        $procedure=trim((string)$d['procedure']);$procedureId=$procedure!==''?self::remember($agentId,'procedural','إجراء مستخلص: '.(string)$d['title'],$procedure,$summaryProject,['consolidated_from'=>array_column($rows,'id'),'scope'=>$scope],70,max(40,min(100,(int)$d['confidence'])),trim((string)$d['entity_key'])?:null,'consolidation',(string)$semanticId):null;
        foreach($rows as $r){db()->prepare('UPDATE agent_memory_bank SET consolidated_into_id=? WHERE id=?')->execute([$semanticId,(int)$r['id']]);db()->prepare("INSERT IGNORE INTO agent_memory_links(from_memory_id,to_memory_id,relation_key,strength) VALUES (?,?,'consolidated_to',0.9000)")->execute([(int)$r['id'],$semanticId]);if($procedureId)db()->prepare("INSERT IGNORE INTO agent_memory_links(from_memory_id,to_memory_id,relation_key,strength) VALUES (?,?,'supports_procedure',0.7000)")->execute([(int)$r['id'],$procedureId]);}
        return ['state'=>'completed','semantic_id'=>$semanticId,'procedure_id'=>$procedureId,'source_count'=>count($rows)];
    }

    public static function stats(int $agentId):array{
        $q=db()->prepare("SELECT memory_kind,COUNT(*) c,AVG(salience) avg_salience FROM agent_memory_bank WHERE agent_id=? AND active=1 GROUP BY memory_kind");$q->execute([$agentId]);$by=[];$total=0;foreach($q->fetchAll() as $r){$by[$r['memory_kind']]=['count'=>(int)$r['c'],'avg_salience'=>round((float)$r['avg_salience'],1)];$total+=(int)$r['c'];}return ['total'=>$total,'by_kind'=>$by];
    }

    public static function link(int $fromId,int $toId,string $relation='related',float $strength=0.5):void{if($fromId<1||$toId<1||$fromId===$toId)return;$relation=preg_replace('/[^a-z0-9_.:-]+/i','_',trim($relation))?:'related';$strength=max(0,min(1,$strength));db()->prepare('INSERT INTO agent_memory_links(from_memory_id,to_memory_id,relation_key,strength) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE strength=VALUES(strength)')->execute([$fromId,$toId,$relation,$strength]);}

    public static function markContradiction(int $oldId,int $newId,bool $supersedes=false):void{self::link($oldId,$newId,$supersedes?'supersedes':'contradicts',$supersedes?1.0:0.9);if($supersedes)db()->prepare('UPDATE agent_memory_bank SET active=0 WHERE id=?')->execute([$oldId]);}

    private static function terms(string $q):array{$q=pb_strtolower(trim($q));if($q==='')return [];$parts=preg_split('/[^\p{L}\p{N}_]+/u',$q)?:[];return array_values(array_unique(array_filter($parts,static fn($x)=>pb_strlen($x)>=3)));}
}
