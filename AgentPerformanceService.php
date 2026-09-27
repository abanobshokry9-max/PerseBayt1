<?php
declare(strict_types=1);

final class AgentPerformanceService {
    public static function snapshotAll():array{
        if(setting('agents.performance_enabled','1')==='0')return ['enabled'=>false];
        $agents=db()->query("SELECT id,slug,display_name FROM agents WHERE is_active=1 AND status<>'disabled' ORDER BY id")->fetchAll();$out=[];
        foreach($agents as $a){try{$out[]=['agent'=>$a['slug']]+self::snapshot((int)$a['id']);}catch(Throwable $e){$out[]=['agent'=>$a['slug'],'state'=>'failed','error'=>pb_substr(Security::redactSecrets($e->getMessage()),0,180)];}}
        put_setting('agents.performance_last_snapshot_at',now_utc());return ['enabled'=>true,'items'=>$out];
    }

    public static function snapshot(int $agentId):array{
        $a=AgentService::byId($agentId);$metrics=self::metrics((string)$a['slug'],$agentId);$date=gmdate('Y-m-d');
        foreach($metrics as $key=>$value){db()->prepare('INSERT INTO agent_kpi_daily(agent_id,kpi_date,kpi_key,value_number,source_json) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE value_number=VALUES(value_number),source_json=VALUES(source_json),updated_at=NOW()')->execute([$agentId,$date,$key,(float)$value,j(['calculated_at'=>now_utc()])]);}
        $score=self::score($agentId,$metrics);$lessons=self::lessons($a,$metrics);$start=gmdate('Y-m-d',time()-6*86400);
        db()->prepare('INSERT INTO agent_performance_snapshots(agent_id,period_start,period_end,score,metrics_json,lessons_json) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score),metrics_json=VALUES(metrics_json),lessons_json=VALUES(lessons_json)')->execute([$agentId,$start,$date,$score,j($metrics),j($lessons)]);
        if($lessons){try{$q=db()->prepare("SELECT COUNT(*) FROM agent_memory_bank WHERE agent_id=? AND source_type='performance' AND source_id=?");$q->execute([$agentId,$date]);if((int)$q->fetchColumn()===0)AgentBrainService::remember($agentId,'feedback','خلاصة أداء '.(string)$a['display_name'],implode("\n",$lessons),null,['metrics'=>$metrics,'score'=>$score],75,85,null,'performance',$date);}catch(Throwable $e){error_log('ELMETR performance memory: '.Security::redactSecrets($e->getMessage(),180));}}
        return ['state'=>'completed','score'=>$score,'metrics'=>$metrics,'lessons'=>$lessons];
    }

    public static function latest():array{$sql="SELECT s.*,a.slug,a.display_name FROM agent_performance_snapshots s JOIN agents a ON a.id=s.agent_id JOIN (SELECT agent_id,MAX(id) id FROM agent_performance_snapshots GROUP BY agent_id) x ON x.id=s.id ORDER BY s.score DESC,a.id";return db()->query($sql)->fetchAll();}
    public static function latestForAgent(int $agentId):array{$q=db()->prepare('SELECT * FROM agent_performance_snapshots WHERE agent_id=? ORDER BY id DESC LIMIT 1');$q->execute([$agentId]);$r=$q->fetch();if(!$r)return ['score'=>null,'metrics'=>[],'lessons'=>[],'snapshot_at'=>null];return ['score'=>$r['score']!==null?(float)$r['score']:null,'metrics'=>json_decode((string)($r['metrics_json']??'{}'),true)?:[],'lessons'=>json_decode((string)($r['lessons_json']??'[]'),true)?:[],'snapshot_at'=>$r['created_at']??null,'period_start'=>$r['period_start']??null,'period_end'=>$r['period_end']??null];}

    private static function metrics(string $slug,int $agentId):array{
        return match($slug){
            'walid'=>[
                'walid.qualified'=>(int)db()->query("SELECT COUNT(*) FROM opportunities WHERE discovered_by_agent_id=$agentId AND fit_status='qualified' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn(),
                'walid.won'=>(int)db()->query("SELECT COUNT(*) FROM opportunities WHERE discovered_by_agent_id=$agentId AND status='won' AND updated_at>=DATE_SUB(NOW(),INTERVAL 90 DAY)")->fetchColumn(),
                'walid.approval_rate'=>self::ratio("SELECT SUM(status IN ('approved','contacted','negotiating','won')) good,COUNT(*) total FROM opportunities WHERE discovered_by_agent_id=$agentId AND owner_decided_at IS NOT NULL AND owner_decided_at>=DATE_SUB(NOW(),INTERVAL 90 DAY)"),
            ],
            'ayman'=>[
                'ayman.completed'=>(int)db()->query("SELECT COUNT(*) FROM tasks WHERE assigned_agent_id=$agentId AND status='completed' AND completed_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn(),
                'ayman.needs_review'=>(int)db()->query("SELECT COUNT(*) FROM tasks WHERE assigned_agent_id=$agentId AND status='needs_review' AND updated_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn(),
            ],
            'emad'=>[
                'emad.verified_fixes'=>(int)db()->query("SELECT COUNT(*) FROM security_findings WHERE status='fixed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn(),
                'emad.open_findings'=>(int)db()->query("SELECT COUNT(*) FROM security_findings WHERE status IN ('open','assigned','fixing','ready_retest','retesting')")->fetchColumn(),
            ],
            'basant'=>[
                'basant.creator_growth'=>self::creatorGrowthCount(),
                'basant.open_events'=>(int)db()->query("SELECT COUNT(*) FROM agency_events WHERE state IN ('open','waiting_supervisor','waiting_creator','scheduled')")->fetchColumn(),
            ],
            'samir-social'=>[
                'samir.publications'=>(int)db()->query("SELECT COUNT(*) FROM social_publications WHERE state='published' AND published_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn()+(int)db()->query("SELECT COUNT(*) FROM social_account_runs WHERE state='completed' AND completed_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn(),
            ],
            'video-director'=>[
                'mona.ready_videos'=>(int)db()->query("SELECT COUNT(*) FROM video_productions WHERE state IN ('ready','approved','published') AND updated_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn(),
            ],
            'ramy'=>[
                'ramy.flow_progress'=>(int)db()->query("SELECT COUNT(DISTINCT project_id) FROM task_events WHERE actor_type='agent' AND actor_id='$agentId' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn(),
                'ramy.active_projects'=>(int)db()->query("SELECT COUNT(*) FROM projects WHERE status NOT IN ('archived','deleted')")->fetchColumn(),
            ],
            default=>['tasks.completed'=>(int)db()->query("SELECT COUNT(*) FROM tasks WHERE assigned_agent_id=$agentId AND status='completed' AND completed_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn()]
        };
    }

    private static function ratio(string $sql):float{$r=db()->query($sql)->fetch()?:[];$t=(int)($r['total']??0);return $t?round(((int)($r['good']??0))*100/$t,2):0.0;}
    private static function creatorGrowthCount():int{$months=db()->query("SELECT DISTINCT data_month FROM agency_creator_monthly ORDER BY data_month DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);if(count($months)<2)return 0;$q=db()->prepare("SELECT COUNT(*) FROM agency_creator_monthly a JOIN agency_creator_monthly b ON b.creator_id=a.creator_id AND b.data_month=? WHERE a.data_month=? AND a.diamonds>b.diamonds AND a.source_type=(CASE WHEN EXISTS(SELECT 1 FROM agency_creator_monthly x WHERE x.creator_id=a.creator_id AND x.data_month=a.data_month AND x.source_type='monthly_earnings') THEN 'monthly_earnings' ELSE a.source_type END)");$q->execute([$months[1],$months[0]]);return (int)$q->fetchColumn();}

    private static function score(int $agentId,array $metrics):float{
        $q=db()->prepare('SELECT kpi_key,direction_key,weight,target_value,baseline_value,min_value,max_value,normalization_key FROM agent_kpi_definitions WHERE (agent_id=? OR agent_id IS NULL) AND active=1');$q->execute([$agentId]);$defs=$q->fetchAll();if(!$defs)return 0.0;$sum=0.0;$w=0.0;
        foreach($defs as $d){$k=(string)$d['kpi_key'];if(!array_key_exists($k,$metrics))continue;if((string)$d['direction_key']==='informational'||(string)$d['normalization_key']==='informational')continue;$v=(float)$metrics[$k];$weight=max(0.0,(float)$d['weight']);if($weight<=0)continue;$normalized=self::normalize($v,$d);$sum+=$normalized*$weight;$w+=$weight;}
        return $w?round($sum/$w,2):0.0;
    }
    private static function normalize(float $v,array $d):float{
        $method=(string)($d['normalization_key']??'target_ratio');$target=$d['target_value']!==null?(float)$d['target_value']:null;$baseline=$d['baseline_value']!==null?(float)$d['baseline_value']:0.0;$min=$d['min_value']!==null?(float)$d['min_value']:null;$max=$d['max_value']!==null?(float)$d['max_value']:null;$direction=(string)$d['direction_key'];
        $n=0.0;
        if($method==='range'&&$min!==null&&$max!==null&&$max>$min){$n=($v-$min)/($max-$min)*100;if($direction==='minimize')$n=100-$n;}
        elseif($method==='inverse_target'||$direction==='minimize'){if($target===null||$target<=0)$n=$v<=0?100:0;else $n=$v<=$target?100:($target/max($v,0.0001))*100;}
        elseif($method==='percentage'&&$target!==null&&$target>0){$n=($v/$target)*100;}
        elseif($target!==null&&$target>$baseline){$n=(($v-$baseline)/($target-$baseline))*100;}
        else{$n=$v;}
        return max(0,min(100,$n));
    }

    private static function lessons(array $a,array $m):array{$slug=(string)$a['slug'];$out=[];if($slug==='walid'&&isset($m['walid.approval_rate'])&&(float)$m['walid.approval_rate']<25)$out[]='نسبة اعتماد الفرص منخفضة؛ ارفع أولوية المصادر/الأنواع التي تحولت فعليًا وقلل النتائج العامة غير الواضحة.';if($slug==='basant'&&!empty($m['basant.open_events']))$out[]='هناك أحداث وكالة مفتوحة؛ الأولوية لإغلاق المتابعات وربط رد المشرفة بالمبدع قبل إنشاء أسئلة جديدة.';if($slug==='emad'&&!empty($m['emad.open_findings']))$out[]='توجد Findings مفتوحة؛ أعط الأولوية لإعادة الاختبار وإغلاق الدائرة بدل زيادة عدد الفحوص فقط.';if(!$out)$out[]='استمر في قياس النتيجة بالأدلة وليس بعدد الأنشطة فقط.';return $out;}
}
