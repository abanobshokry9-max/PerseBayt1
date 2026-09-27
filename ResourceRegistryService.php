<?php
declare(strict_types=1);
final class ResourceRegistryService {
    public static function register(string $type,string $key,string $label,int $agentId=0,?int $projectId=null,string $external='',array $meta=[],string $state='active'):int{
        $type=strtolower(trim($type));$key=trim($key);if($type===''||$key==='')throw new RuntimeException('resource_identity_required');
        if($agentId>0)AgentService::byId($agentId);
        db()->prepare("INSERT INTO resource_registry(resource_type,resource_key,label,owner_agent_id,project_id,external_ref,state,metadata_json) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),owner_agent_id=VALUES(owner_agent_id),project_id=VALUES(project_id),external_ref=VALUES(external_ref),state=VALUES(state),metadata_json=VALUES(metadata_json),updated_at=NOW()")
          ->execute([$type,substr($key,0,190),substr($label?:$key,0,220),$agentId?:null,$projectId,$external?:null,$state,$meta?j(self::clean($meta)):null]);
        $q=db()->prepare('SELECT id FROM resource_registry WHERE resource_type=? AND resource_key=?');$q->execute([$type,substr($key,0,190)]);$id=(int)$q->fetchColumn();self::event($id,'registered','verified',['state'=>$state]);return $id;
    }
    public static function state(int $id,string $state,array $meta=[]):void{db()->prepare('UPDATE resource_registry SET state=?,metadata_json=CASE WHEN ? IS NULL THEN metadata_json ELSE ? END,updated_at=NOW() WHERE id=?')->execute([$state,$meta?j(self::clean($meta)):null,$meta?j(self::clean($meta)):null,$id]);self::event($id,'state.'.$state,'verified',$meta);}
    public static function event(int $id,string $event,string $result='executed',array $meta=[]):void{try{db()->prepare('INSERT INTO resource_registry_events(resource_id,event_key,result_state,metadata_json) VALUES (?,?,?,?)')->execute([$id,substr($event,0,120),substr($result,0,40),$meta?j(self::clean($meta)):null]);}catch(Throwable){}}
    public static function all(int $limit=300):array{$limit=max(1,min(500,$limit));return db()->query('SELECT r.*,a.display_name agent_name,p.name project_name FROM resource_registry r LEFT JOIN agents a ON a.id=r.owner_agent_id LEFT JOIN projects p ON p.id=r.project_id ORDER BY r.id DESC LIMIT '.$limit)->fetchAll();}
    private static function clean(array $a):array{foreach($a as $k=>&$v){if(preg_match('/password|secret|token|api[_-]?key|authorization/i',(string)$k))$v='[REDACTED]';elseif(is_array($v))$v=self::clean($v);elseif(is_string($v))$v=Security::redactSecrets($v,2000);}unset($v);return $a;}
}
