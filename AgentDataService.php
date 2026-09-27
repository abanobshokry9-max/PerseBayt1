<?php
declare(strict_types=1);

final class AgentDataService {
    private const TYPES=['text','long_text','number','decimal','date','datetime','boolean','json','url','email','phone','status'];

    private static function key(string $value):string{
        $value=strtolower(trim($value));
        $value=preg_replace('/[^a-z0-9_]+/','_',$value);
        $value=trim((string)$value,'_');
        if($value===''||strlen($value)>80)throw new RuntimeException('agent_data_key_invalid');
        return $value;
    }
    private static function assertNoSecretField(string $key,string $label):void{
        if(preg_match('/(?:pass(?:word)?|secret|token|api[_-]?key|private[_-]?key|access[_-]?token|credential)/i',$key.' '.$label))throw new RuntimeException('agent_data_secret_field_blocked');
    }
    public static function types():array{return self::TYPES;}
    public static function schemasForAgent(int $agentId,bool $activeOnly=true):array{
        AgentService::byId($agentId);
        $sql='SELECT s.*,(SELECT COUNT(*) FROM agent_data_fields f WHERE f.schema_id=s.id) field_count,(SELECT COUNT(*) FROM agent_data_rows r WHERE r.schema_id=s.id) row_count FROM agent_data_schemas s WHERE s.agent_id=?'.($activeOnly?' AND s.is_active=1':'').' ORDER BY s.id DESC';
        $q=db()->prepare($sql);$q->execute([$agentId]);return $q->fetchAll();
    }
    public static function schema(int $schemaId):array{
        $q=db()->prepare('SELECT s.*,a.display_name agent_name,a.slug agent_slug FROM agent_data_schemas s JOIN agents a ON a.id=s.agent_id WHERE s.id=?');$q->execute([$schemaId]);$s=$q->fetch();if(!$s)throw new RuntimeException('agent_data_schema_not_found');return $s;
    }
    public static function fields(int $schemaId):array{
        $q=db()->prepare('SELECT * FROM agent_data_fields WHERE schema_id=? ORDER BY sort_order,id');$q->execute([$schemaId]);return $q->fetchAll();
    }
    public static function rows(int $schemaId,int $limit=100):array{
        self::schema($schemaId);$limit=max(1,min(300,$limit));$q=db()->prepare('SELECT r.*,p.name project_name FROM agent_data_rows r LEFT JOIN projects p ON p.id=r.project_id WHERE r.schema_id=? ORDER BY r.id DESC LIMIT '.$limit);$q->execute([$schemaId]);return $q->fetchAll();
    }
    public static function createSchema(int $agentId,string $key,string $label,string $description='',string $scope='agent'):int{
        AgentService::byId($agentId);$key=self::key($key);$label=trim($label);$description=trim($description);if($label===''||pb_strlen($label)>160||pb_strlen($description)>3000)throw new RuntimeException('agent_data_schema_invalid');if(!in_array($scope,['agent','project'],true))$scope='agent';self::assertNoSecretField($key,$label);
        $q=db()->prepare('INSERT INTO agent_data_schemas(agent_id,schema_key,label,description,scope,is_active) VALUES (?,?,?,?,?,1)');$q->execute([$agentId,$key,$label,$description?:null,$scope]);$id=(int)db()->lastInsertId();$actor=Audit::actor();Audit::log((string)$actor['type'],(string)$actor['id'],'agent_data.schema_create','agent_data_schema',(string)$id,'verified',null,null,['agent_id'=>$agentId,'schema_key'=>$key,'scope'=>$scope]);return $id;
    }
    public static function addField(int $schemaId,string $key,string $label,string $type,bool $required=false,string $defaultValue=''):int{
        $s=self::schema($schemaId);$key=self::key($key);$label=trim($label);$type=trim($type);if($label===''||pb_strlen($label)>160||!in_array($type,self::TYPES,true))throw new RuntimeException('agent_data_field_invalid');self::assertNoSecretField($key,$label);$defaultValue=trim($defaultValue);if($defaultValue!=='')MemoryService::assertSafeMemory($defaultValue);
        $q=db()->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM agent_data_fields WHERE schema_id=?');$q->execute([$schemaId]);$sort=(int)$q->fetchColumn();
        $q=db()->prepare('INSERT INTO agent_data_fields(schema_id,field_key,label,field_type,is_required,default_value,sort_order,config_json) VALUES (?,?,?,?,?,?,?,?)');$q->execute([$schemaId,$key,$label,$type,$required?1:0,$defaultValue!==''?$defaultValue:null,$sort,'{}']);$id=(int)db()->lastInsertId();$actor=Audit::actor();Audit::log((string)$actor['type'],(string)$actor['id'],'agent_data.field_create','agent_data_field',(string)$id,'verified',null,null,['agent_id'=>(int)$s['agent_id'],'schema_id'=>$schemaId,'field_key'=>$key,'field_type'=>$type]);return $id;
    }
    private static function value(array $field,$raw){
        $type=(string)$field['field_type'];
        if(is_array($raw)&&$type!=='json')$raw=implode(', ',array_map('strval',$raw));
        $raw=is_string($raw)?trim($raw):$raw;
        if($raw===''||$raw===null){if((int)$field['is_required']===1&&($field['default_value']??null)===null)throw new RuntimeException('agent_data_required:'.$field['field_key']);return $field['default_value']??null;}
        return match($type){
            'number'=>(function()use($raw,$field){if(!is_numeric($raw))throw new RuntimeException('agent_data_number_invalid:'.$field['field_key']);return (int)$raw;})(),
            'decimal'=>(function()use($raw,$field){if(!is_numeric($raw))throw new RuntimeException('agent_data_decimal_invalid:'.$field['field_key']);return (float)$raw;})(),
            'boolean'=>in_array(strtolower((string)$raw),['1','true','yes','on','نعم'],true),
            'email'=>(function()use($raw,$field){$v=(string)$raw;if(!filter_var($v,FILTER_VALIDATE_EMAIL))throw new RuntimeException('agent_data_email_invalid:'.$field['field_key']);return $v;})(),
            'url'=>(function()use($raw,$field){$v=(string)$raw;if(!filter_var($v,FILTER_VALIDATE_URL))throw new RuntimeException('agent_data_url_invalid:'.$field['field_key']);return $v;})(),
            'phone'=>pb_substr(preg_replace('/[^0-9+().\-\s]/','',(string)$raw),0,80),
            'date'=>(function()use($raw,$field){$v=(string)$raw;if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v))throw new RuntimeException('agent_data_date_invalid:'.$field['field_key']);return $v;})(),
            'datetime'=>(function()use($raw,$field){$v=(string)$raw;if(strtotime($v)===false)throw new RuntimeException('agent_data_datetime_invalid:'.$field['field_key']);return $v;})(),
            'json'=>(function()use($raw,$field){if(is_array($raw))return $raw;try{return json_decode((string)$raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('agent_data_json_invalid:'.$field['field_key']);}})(),
            'long_text'=>pb_substr((string)$raw,0,50000),
            default=>pb_substr((string)$raw,0,5000),
        };
    }
    public static function addRow(int $schemaId,array $input,?int $projectId=null,string $rowLabel=''):int{
        $s=self::schema($schemaId);$agentId=(int)$s['agent_id'];if((string)$s['scope']==='project'&&(!$projectId||$projectId<1))throw new RuntimeException('agent_data_project_required');if($projectId){ProjectService::get($projectId);if(!Permissions::project($agentId,$projectId,'read')&&AgentService::bySlug('ramy')['id']!=$agentId){/* owner may still store reference; runner sees only project-linked context when it has project scope */}}
        $fields=self::fields($schemaId);if(!$fields)throw new RuntimeException('agent_data_fields_required');$data=[];foreach($fields as $f){$k=(string)$f['field_key'];$data[$k]=self::value($f,$input[$k]??null);}MemoryService::assertSafeMemory(j($data));$rowLabel=trim($rowLabel);if($rowLabel!=='')MemoryService::assertSafeMemory($rowLabel);$actor=Audit::actor();$q=db()->prepare('INSERT INTO agent_data_rows(schema_id,agent_id,project_id,row_label,data_json,created_by_type,created_by_id) VALUES (?,?,?,?,?,?,?)');$q->execute([$schemaId,$agentId,$projectId,$rowLabel!==''?pb_substr($rowLabel,0,190):null,j($data),in_array($actor['type'],['owner','agent','system'],true)?$actor['type']:'system',(string)$actor['id']]);$id=(int)db()->lastInsertId();Audit::log((string)$actor['type'],(string)$actor['id'],'agent_data.row_create','agent_data_row',(string)$id,'verified',$projectId,null,['agent_id'=>$agentId,'schema_id'=>$schemaId,'fields'=>array_keys($data)]);return $id;
    }

    public static function schemaByKey(int $agentId,string $key):array{
        $key=self::key($key);$q=db()->prepare('SELECT * FROM agent_data_schemas WHERE agent_id=? AND schema_key=? LIMIT 1');$q->execute([$agentId,$key]);$s=$q->fetch();if(!$s)throw new RuntimeException('agent_data_schema_not_found');return $s;
    }
    public static function ensureSchema(int $agentId,string $key,string $label,string $description='',string $scope='agent',array $fields=[]):int{
        AgentService::byId($agentId);$key=self::key($key);self::assertNoSecretField($key,$label);$q=db()->prepare('SELECT id FROM agent_data_schemas WHERE agent_id=? AND schema_key=? LIMIT 1');$q->execute([$agentId,$key]);$id=(int)($q->fetchColumn()?:0);if(!$id)$id=self::createSchema($agentId,$key,$label,$description,$scope);
        $existing=[];foreach(self::fields($id) as $f)$existing[(string)$f['field_key']]=true;foreach($fields as $f){if(!is_array($f))continue;$fk=self::key((string)($f['key']??$f['field_key']??''));if(isset($existing[$fk]))continue;self::addField($id,$fk,(string)($f['label']??$fk),(string)($f['type']??'text'),!empty($f['required']),(string)($f['default']??''));$existing[$fk]=true;}return $id;
    }
    public static function contextForAgent(int $agentId,?int $projectId=null,int $limit=30):array{
        if(!AgentService::tool($agentId,'data_studio'))return [];$limit=max(5,min(80,$limit));$q=db()->prepare('SELECT s.id,s.schema_key,s.label,s.description,s.scope FROM agent_data_schemas s WHERE s.agent_id=? AND s.is_active=1 ORDER BY s.id');$q->execute([$agentId]);$schemas=$q->fetchAll();$out=[];$remaining=$limit;
        foreach($schemas as $s){if($remaining<=0)break;$fields=self::fields((int)$s['id']);$fieldMap=[];foreach($fields as $f)$fieldMap[(string)$f['field_key']]=['label'=>(string)$f['label'],'type'=>(string)$f['field_type']];$args=[(int)$s['id']];$where='schema_id=?';if($projectId){$where.=' AND (project_id IS NULL OR project_id=?)';$args[]=$projectId;}else{$where.=' AND project_id IS NULL';}$take=min(12,$remaining);$r=db()->prepare('SELECT id,row_label,project_id,data_json,updated_at FROM agent_data_rows WHERE '.$where.' ORDER BY id DESC LIMIT '.$take);$r->execute($args);$rows=[];foreach($r->fetchAll() as $row){$data=json_decode((string)$row['data_json'],true);if(!is_array($data))$data=[];$rows[]=['label'=>$row['row_label'],'project_id'=>$row['project_id']!==null?(int)$row['project_id']:null,'data'=>$data,'updated_at'=>$row['updated_at']];}$remaining-=count($rows);if($rows)$out[]=['schema_key'=>$s['schema_key'],'label'=>$s['label'],'scope'=>$s['scope'],'fields'=>$fieldMap,'rows'=>$rows];}
        return $out;
    }
}
