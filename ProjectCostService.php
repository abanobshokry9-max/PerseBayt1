<?php
declare(strict_types=1);
final class ProjectCostService {
    private const KINDS=['ai','api','hosting','domain','theme_plugin','assets','contractor','developer','other'];
    public static function add(int $projectId,string $kind,float $amount,string $currency,string $note='',?int $taskId=null):int{
        ProjectService::get($projectId);$kind=in_array($kind,self::KINDS,true)?$kind:'other';$amount=round($amount,2);if($amount<=0)throw new RuntimeException('invalid_cost_amount');$currency=OpportunityMoney::normalizeCurrency($currency);if($currency==='UNK')throw new RuntimeException('invalid_cost_currency');$u=Auth::user();db()->prepare('INSERT INTO project_cost_entries(project_id,task_id,cost_kind,amount,currency,note,source,created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$projectId,$taskId?:null,$kind,$amount,$currency,trim($note)?:null,'manual',(int)($u['id']??1)]);$id=(int)db()->lastInsertId();Audit::log('owner',(string)($u['id']??1),'project.cost_add','project_cost',(string)$id,'verified',$projectId,$taskId,['amount'=>$amount,'currency'=>$currency,'kind'=>$kind]);return $id;
    }
    public static function projectSummary(int $projectId):array{
        $q=db()->prepare("SELECT q.currency,q.amount project_value,(SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.project_id=q.project_id AND p.status='confirmed' AND p.currency=q.currency) collected FROM quotes q WHERE q.project_id=? AND q.status IN ('sent','accepted') ORDER BY q.id DESC LIMIT 1");$q->execute([$projectId]);$r=$q->fetch()?:['currency'=>(string)setting('business.currency','EGP'),'project_value'=>0,'collected'=>0];$currency=(string)$r['currency'];$q=db()->prepare('SELECT COALESCE(SUM(amount),0) FROM project_cost_entries WHERE project_id=? AND currency=? AND status<>\'void\'');$q->execute([$projectId,$currency]);$cost=(float)$q->fetchColumn();return ['currency'=>$currency,'project_value'=>(float)$r['project_value'],'collected'=>(float)$r['collected'],'remaining'=>max(0,(float)$r['project_value']-(float)$r['collected']),'actual_cost'=>$cost,'actual_profit'=>(float)$r['collected']-$cost,'projected_profit'=>(float)$r['project_value']-$cost];
    }
    public static function kinds():array{return self::KINDS;}
}
