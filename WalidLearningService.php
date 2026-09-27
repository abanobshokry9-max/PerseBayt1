<?php
declare(strict_types=1);

final class WalidLearningService {
    public static function sync():array{
        $rows=db()->query("SELECT id,status,owner_decided_at,estimated_cost,projected_profit,suggested_offer,source,country,category,opportunity_type FROM opportunities WHERE owner_decided_at IS NOT NULL OR status IN ('contacted','negotiating','won','lost') ORDER BY id DESC LIMIT 1000")->fetchAll();$n=0;
        foreach($rows as $r){$decision=in_array($r['status'],['approved','contacted','negotiating','won'],true)?'approved':($r['status']==='rejected'?'rejected':'unknown');$final=in_array($r['status'],['contacted','negotiating','won','lost'],true)?$r['status']:'unknown';db()->prepare("INSERT INTO opportunity_feedback(opportunity_id,owner_decision,final_status,actual_profit,feedback_weight,learned_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE owner_decision=VALUES(owner_decision),final_status=VALUES(final_status),actual_profit=COALESCE(opportunity_feedback.actual_profit,VALUES(actual_profit)),learned_at=NOW()")->execute([(int)$r['id'],$decision,$final,$r['status']==='won'?(float)$r['projected_profit']:null,self::weight($decision,$final)]);$n++;}
        return ['synced'=>$n,'profile'=>self::profile()];
    }
    public static function ownerOutcome(int $opportunityId,string $decision,string $finalStatus='unknown',?float $revenue=null,?float $cost=null,string $notes=''):void{
        if(!in_array($decision,['unknown','approved','rejected'],true))throw new RuntimeException('feedback_decision_invalid');if(!in_array($finalStatus,['unknown','contacted','negotiating','won','lost'],true))throw new RuntimeException('feedback_status_invalid');$profit=$revenue!==null&&$cost!==null?$revenue-$cost:null;db()->prepare("INSERT INTO opportunity_feedback(opportunity_id,owner_decision,final_status,actual_revenue,actual_cost,actual_profit,feedback_weight,learned_at,notes) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE owner_decision=VALUES(owner_decision),final_status=VALUES(final_status),actual_revenue=VALUES(actual_revenue),actual_cost=VALUES(actual_cost),actual_profit=VALUES(actual_profit),feedback_weight=VALUES(feedback_weight),learned_at=NOW(),notes=VALUES(notes)")->execute([$opportunityId,$decision,$finalStatus,$revenue,$cost,$profit,self::weight($decision,$finalStatus),now_utc(),pb_substr(trim($notes),0,8000)?:null]);
    }
    public static function profile():array{
        $sql="SELECT o.source,o.country,o.category,o.opportunity_type,f.owner_decision,f.final_status,COALESCE(f.actual_profit,o.projected_profit,0) profit,f.feedback_weight FROM opportunity_feedback f JOIN opportunities o ON o.id=f.opportunity_id WHERE f.learned_at>=DATE_SUB(NOW(),INTERVAL 365 DAY) ORDER BY f.learned_at DESC LIMIT 1500";$rows=db()->query($sql)->fetchAll();$groups=['source'=>[],'country'=>[],'category'=>[],'opportunity_type'=>[]];$tot=['approved'=>0,'rejected'=>0,'won'=>0,'lost'=>0,'profit'=>0.0];
        foreach($rows as $r){$w=(float)$r['feedback_weight'];if($r['owner_decision']==='approved')$tot['approved']++;elseif($r['owner_decision']==='rejected')$tot['rejected']++;if($r['final_status']==='won')$tot['won']++;elseif($r['final_status']==='lost')$tot['lost']++;$tot['profit']+=(float)$r['profit'];foreach(array_keys($groups) as $k){$v=trim((string)($r[$k]??''));if($v==='')$v='unknown';if(!isset($groups[$k][$v]))$groups[$k][$v]=['score'=>0.0,'samples'=>0,'wins'=>0,'profit'=>0.0];$signal=match(true){$r['final_status']==='won'=>4.0,$r['owner_decision']==='approved'=>2.0,$r['final_status']==='lost'=>-2.0,$r['owner_decision']==='rejected'=>-1.5,default=>0.0};$groups[$k][$v]['score']+=$signal*$w;$groups[$k][$v]['samples']++;$groups[$k][$v]['wins']+=($r['final_status']==='won'?1:0);$groups[$k][$v]['profit']+=(float)$r['profit'];}}
        foreach($groups as &$g){uasort($g,static fn($a,$b)=>($b['score']<=>$a['score'])?:($b['profit']<=>$a['profit']));$g=array_slice($g,0,12,true);}unset($g);$totalDec=$tot['approved']+$tot['rejected'];$tot['approval_rate']=$totalDec?round($tot['approved']*100/$totalDec,1):0.0;return ['totals'=>$tot,'preferences'=>$groups,'samples'=>count($rows)];
    }

    public static function learnedQueries(int $limit=6):array{
        $p=self::profile();$out=[];$limit=max(1,min(12,$limit));$pref=(array)($p['preferences']??[]);
        $cats=array_slice(array_keys((array)($pref['category']??[])),0,3);$countries=array_slice(array_keys((array)($pref['country']??[])),0,3);$types=array_slice(array_keys((array)($pref['opportunity_type']??[])),0,3);
        foreach($cats as $cat){if($cat==='unknown')continue;$out[]='مطلوب '.trim($cat).' مشروع ميزانية عميل يبحث عن منفذ';}
        foreach($types as $type){if($type==='unknown')continue;$out[]='looking for '.trim($type).' freelance project fixed budget';}
        foreach($countries as $country){if($country==='unknown')continue;$out[]=trim($country).' مطلوب مشروع رقمي ميزانية مستقل شركة';}
        $out=array_values(array_unique(array_filter(array_map('trim',$out))));return array_slice($out,0,$limit);
    }
    public static function preferenceAdjustment(string $source,string $country,string $category,string $type):array{
        $p=self::profile();$delta=0.0;$parts=[];foreach(['source'=>$source,'country'=>$country,'category'=>$category,'opportunity_type'=>$type] as $k=>$v){$v=trim($v)?:'unknown';$g=(array)($p['preferences'][$k]??[]);if(isset($g[$v])){$samples=(int)($g[$v]['samples']??0);$raw=(float)($g[$v]['score']??0);if($samples>=2){$d=max(-5.0,min(8.0,$raw/max(1,$samples)));$delta+=$d;$parts[$k]=round($d,2);}}}$explore=max(0.05,min(0.35,(float)setting('opportunities.learning_exploration_ratio','0.15')));$delta*=(1-$explore);return ['delta'=>max(-10,min(12,(int)round($delta))),'components'=>$parts,'exploration_ratio'=>$explore];
    }

    public static function promptContext():string{$p=self::profile();return "تعلم وليد المغلق الحلقة من قرارات المالك ونتائج البيع (لا تعتبره ضمانًا):\n".j($p);}
    private static function weight(string $decision,string $final):float{return match(true){$final==='won'=>3.0,$final==='lost'=>2.0,$decision==='approved'=>1.5,$decision==='rejected'=>1.2,default=>1.0};}
}
