<?php
declare(strict_types=1);
final class Audit {
    private static string $currentType='system';
    private static string $currentId='runtime';
    public static function setActor(string $type,string $id):void{
        if(!in_array($type,['owner','agent','customer','system','anonymous'],true))$type='system';
        self::$currentType=$type;self::$currentId=$id!==''?$id:'runtime';
    }
    public static function actor():array{return ['type'=>self::$currentType,'id'=>self::$currentId];}
    public static function log(string $actorType,string $actorId,string $action,?string $entityType=null,?string $entityId=null,string $result='executed',?int $projectId=null,?int $taskId=null,array $meta=[]):void{
        // الخدمات القديمة كانت تمرر owner/1 افتراضيًا. نعتمد الفاعل الحقيقي من سياق التنفيذ حتى لا ننسب شغل رامي أو العامل للمالك بالخطأ.
        if($actorType==='owner'&&$actorId==='1'&&!(self::$currentType==='owner'&&self::$currentId==='1')){$actorType=self::$currentType;$actorId=self::$currentId;}
        $allowedResults=['attempted','executed','verified','failed','blocked'];
        if(!in_array($result,$allowedResults,true)){$meta['_audit_result_original']=$result;$result='failed';}
        $q=db()->prepare('INSERT INTO audit_logs(actor_type,actor_id,action,entity_type,entity_id,project_id,task_id,result,ip_address,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$actorType,$actorId,$action,$entityType,$entityId,$projectId,$taskId,$result,request_ip(),$meta?j($meta):null]);
    }
}
