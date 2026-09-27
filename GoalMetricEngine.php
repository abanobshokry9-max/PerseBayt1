<?php
declare(strict_types=1);

final class GoalMetricEngine {
    public static function syncAll():array{
        if(setting('goals.metric_sync_enabled','1')==='0')return ['enabled'=>false,'items'=>[]];
        $goals=db()->query("SELECT * FROM company_goals WHERE state='active' AND COALESCE(metric_key,'')<>'' ORDER BY id")->fetchAll();
        $items=[];
        foreach($goals as $g){try{$items[]=self::syncGoal((int)$g['id']);}catch(Throwable $e){$items[]=['goal_id'=>(int)$g['id'],'state'=>'failed','error'=>pb_substr(Security::redactSecrets($e->getMessage()),0,180)];}}
        put_setting('goals.metric_sync_last_at',now_utc());
        return ['enabled'=>true,'items'=>$items];
    }

    public static function syncGoal(int $goalId):array{
        $g=CompanyGoalService::get($goalId);$metric=trim((string)($g['metric_key']??''));if($metric==='')return ['goal_id'=>$goalId,'state'=>'skipped','reason'=>'metric_missing'];
        $current=self::value($metric,$g);$target=$g['target_value']!==null?(float)$g['target_value']:null;
        $progress=$target!==null&&$target>0?max(0,min(999.999,($current/$target)*100)):null;
        [$forecast,$health]=self::forecast($g,$current,$target);
        $completed=$target!==null&&$target>0&&$current>=$target;
        db()->prepare("UPDATE company_goals SET current_value=?,progress_percent=?,forecast_value=?,health_state=?,last_metric_sync_at=NOW(),state=IF(?=1,'completed',state),completed_at=IF(?=1,COALESCE(completed_at,NOW()),completed_at),updated_at=NOW() WHERE id=?")
            ->execute([$current,$progress,$forecast,$completed?'achieved':$health,$completed?1:0,$completed?1:0,$goalId]);
        if($completed){
            try{db()->prepare("UPDATE company_goal_agents SET state='completed',last_progress_at=NOW() WHERE goal_id=? AND state='active'")->execute([$goalId]);}catch(Throwable){}
            try{Notifications::add('success','goals','تم تحقيق هدف الشركة',(string)$g['title'].' — '.$current.' '.(string)($g['unit_label']??''),'company_goal',(string)$goalId);}catch(Throwable){}
        }
        return ['goal_id'=>$goalId,'metric'=>$metric,'current'=>$current,'target'=>$target,'progress_percent'=>$progress,'forecast'=>$forecast,'health'=>$completed?'achieved':$health,'state'=>$completed?'completed':'active'];
    }

    public static function value(string $metric,array $goal=[]):float{
        $start=(string)($goal['start_at']??'');$startSql=$start!==''?db()->quote($start):"DATE_SUB(NOW(),INTERVAL 365 DAY)";
        return match($metric){
            'sales.revenue'=>(float)db()->query("SELECT COALESCE(SUM(actual_revenue),0) FROM opportunity_feedback f JOIN opportunities o ON o.id=f.opportunity_id WHERE f.final_status='won' AND o.updated_at>=$startSql")->fetchColumn(),
            'deals.won'=>(float)db()->query("SELECT COUNT(*) FROM opportunities WHERE status='won' AND updated_at>=$startSql")->fetchColumn(),
            'walid.qualified'=>(float)db()->query("SELECT COUNT(*) FROM opportunities o JOIN agents a ON a.id=o.discovered_by_agent_id WHERE a.slug='walid' AND o.fit_status='qualified' AND o.created_at>=$startSql")->fetchColumn(),
            'agency.diamonds'=>self::agencyDiamonds($goal),
            'projects.delivered'=>(float)db()->query("SELECT COUNT(*) FROM projects WHERE workflow_stage IN ('delivered','closed') AND updated_at>=$startSql")->fetchColumn(),
            'creator.growth'=>(float)self::creatorGrowthCount(),
            'social.publications'=>(float)db()->query("SELECT COUNT(*) FROM social_publications WHERE state='published' AND published_at>=$startSql")->fetchColumn(),
            default=>self::customMetric($metric,$goal)
        };
    }

    private static function agencyDiamonds(array $goal):float{
        $meta=json_decode((string)($goal['metadata_json']??'{}'),true)?:[];$month=trim((string)($meta['data_month']??''));
        if($month==='')$month=(string)(db()->query("SELECT MAX(data_month) FROM agency_creator_monthly")->fetchColumn()?:'');if($month==='')return 0.0;
        $q=db()->prepare("SELECT COALESCE(SUM(x.diamonds),0) FROM (SELECT creator_id,MAX(diamonds) diamonds FROM agency_creator_monthly WHERE data_month=? GROUP BY creator_id) x");$q->execute([$month]);return (float)$q->fetchColumn();
    }
    private static function creatorGrowthCount():int{$months=db()->query("SELECT DISTINCT data_month FROM agency_creator_monthly ORDER BY data_month DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);if(count($months)<2)return 0;$q=db()->prepare("SELECT COUNT(*) FROM (SELECT a.creator_id,MAX(a.diamonds) cur,MAX(b.diamonds) prev FROM agency_creator_monthly a JOIN agency_creator_monthly b ON b.creator_id=a.creator_id AND b.data_month=? WHERE a.data_month=? GROUP BY a.creator_id HAVING cur>prev) x");$q->execute([$months[1],$months[0]]);return (int)$q->fetchColumn();}
    private static function customMetric(string $metric,array $goal):float{throw new RuntimeException('goal_metric_unsupported:'.$metric);}

    private static function forecast(array $g,float $current,?float $target):array{
        if($target===null||$target<=0)return [null,'unknown'];$start=utc_ts((string)($g['start_at']??''));$due=utc_ts((string)($g['due_at']??''));$now=time();
        if($start===false||$due===false||$due<=$start)return [null,$current>=$target?'achieved':'unknown'];
        $elapsed=max(1,$now-$start);$total=max(1,$due-$start);$ratio=max(0,min(1,$elapsed/$total));$expected=$target*$ratio;$forecast=$ratio>0?$current/$ratio:$current;
        if($current>=$target)return [$forecast,'achieved'];
        if($now>$due)return [$forecast,'behind'];
        if($current>=$expected*0.92)return [$forecast,'on_track'];
        if($current>=$expected*0.70)return [$forecast,'at_risk'];
        return [$forecast,'behind'];
    }
}
