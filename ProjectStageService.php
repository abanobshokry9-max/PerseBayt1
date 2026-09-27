<?php
declare(strict_types=1);
final class ProjectStageService {
    private const STAGES=['discovered','owner_review','approved','negotiating','agreed','deposit_pending','awaiting_execution','in_development','awaiting_qa','qa','needs_fix','ready_delivery','delivered','closed'];
    private const ALIASES=['accepted'=>'approved','waiting_execution'=>'awaiting_execution','in_progress'=>'in_development','waiting_review'=>'awaiting_qa','in_review'=>'qa'];
    private const NEXT=[
        'discovered'=>['owner_review','approved','closed'],
        'owner_review'=>['approved','closed'],
        'approved'=>['negotiating','closed'],
        'negotiating'=>['agreed','closed'],
        'agreed'=>['deposit_pending','awaiting_execution','closed'],
        'deposit_pending'=>['awaiting_execution','closed'],
        'awaiting_execution'=>['in_development','closed'],
        'in_development'=>['awaiting_qa','needs_fix','closed'],
        'awaiting_qa'=>['qa','needs_fix','closed'],
        'qa'=>['needs_fix','ready_delivery','closed'],
        'needs_fix'=>['in_development','awaiting_qa','closed'],
        'ready_delivery'=>['delivered','needs_fix','closed'],
        'delivered'=>['closed','needs_fix'],
        'closed'=>[]
    ];
    public static function normalize(string $stage):string{return self::ALIASES[$stage]??$stage;}
    public static function set(int $projectId,string $to,string $actorType='system',string $actorId='system',string $reason='',bool $force=false):void{
        $p=ProjectService::get($projectId);$from=self::normalize((string)($p['workflow_stage']??'discovered'));$to=self::normalize($to);
        if(!in_array($to,self::STAGES,true))throw new RuntimeException('invalid_project_stage');
        if($from===$to){if((string)($p['workflow_stage']??'')!==$to)db()->prepare('UPDATE projects SET workflow_stage=? WHERE id=?')->execute([$to,$projectId]);return;}
        if(!$force&&!in_array($to,self::NEXT[$from]??[],true))throw new RuntimeException('invalid_project_stage_transition');
        db()->prepare('UPDATE projects SET workflow_stage=? WHERE id=?')->execute([$to,$projectId]);
        db()->prepare('INSERT INTO project_stage_events(project_id,from_stage,to_stage,actor_type,actor_id,reason) VALUES (?,?,?,?,?,?)')->execute([$projectId,$from,$to,$actorType,$actorId,$reason?:null]);
        ProjectChatService::post($projectId,'system','workflow','انتقلت مرحلة المشروع من '.AdminUi::label($from).' إلى '.AdminUi::label($to).($reason?' — '.$reason:''),'system','project_stage',(string)db()->lastInsertId(),false,['from'=>$from,'to'=>$to]);
    }
    public static function stages():array{return self::STAGES;}
}
