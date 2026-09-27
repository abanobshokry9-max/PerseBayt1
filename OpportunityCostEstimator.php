<?php
declare(strict_types=1);

/**
 * Adaptive internal-cost estimator.
 * - Uses same-currency historical feedback first.
 * - Uses AI estimate when available.
 * - Falls back to a configurable effort heuristic so Walid never shows a blank internal cost.
 * Client-advertised currency is still kept separately by OpportunityMoney; when the client budget is
 * unknown the caller may use the company's internal currency for cost/offer estimates.
 */
final class OpportunityCostEstimator {
    public static function internalCurrency():string {
        $c=OpportunityMoney::normalizeCurrency((string)setting('business.currency','EGP'));
        return $c==='UNK'?'EGP':$c;
    }

    public static function internalDayCost(string $currency):float {
        $currency=OpportunityMoney::normalizeCurrency($currency);
        if($currency==='UNK')$currency=self::internalCurrency();
        $specific=(float)setting('business.internal_day_cost_'.strtolower($currency),'0');
        if($specific>0)return $specific;
        // The generic day-cost field is denominated in the company's base currency only. Never reuse
        // an EGP day cost as if it were USD/SAR/AED just because a buyer published another currency.
        if($currency===self::internalCurrency()){
            $generic=(float)setting('business.internal_day_cost','0');
            if($generic>0)return $generic;
        }
        return match($currency){
            'USD'=>35.0,'EUR'=>32.0,'GBP'=>30.0,'SAR'=>130.0,'AED'=>130.0,'KWD'=>11.0,'QAR'=>130.0,
            default=>1500.0,
        };
    }

    /** Returns deterministic effort defaults that are intentionally conservative and owner-editable. */
    public static function heuristic(array $evaluation,array $base,string $currency):array {
        $currency=OpportunityMoney::normalizeCurrency($currency);if($currency==='UNK')$currency=self::internalCurrency();
        $text=pb_strtolower(trim(
            (string)($base['title']??'').' '.(string)($base['snippet']??'').' '.
            (string)($evaluation['title_ar']??'').' '.(string)($evaluation['summary_ar']??'').' '.
            (string)($evaluation['details_ar']??'').' '.(string)($evaluation['opportunity_type']??'').' '.
            implode(' ',array_map('strval',(array)($evaluation['tags']??[])))
        ));
        $days=4;$difficulty='medium';$profile='web_project';
        if(preg_match('/(bug\s*fix|fix(?:ing)?|troubleshoot|small\s+change|minor\s+change|إصلاح|اصلاح|مشكلة|خطأ|تعديل بسيط)/u',$text)){$days=2;$difficulty='easy';$profile='bug_fix';}
        if(preg_match('/(landing\s*page|صفحة هبوط)/u',$text)){$days=2;$difficulty='easy';$profile='landing_page';}
        if(preg_match('/(api\s+integration|rest\s*api|تكامل\s*api|ربط\s*api|crm integration|ربط.*crm)/u',$text)){$days=max($days,3);$difficulty='medium';$profile='api_integration';}
        if(preg_match('/(n8n|zapier|make\.com|workflow\s+automation|automation|أتمتة|اتمتة)/u',$text)){$days=max($days,3);$difficulty='medium';$profile='automation';}
        if(preg_match('/(wordpress|woocommerce|ووردبريس|ووكومرس)/u',$text)){$days=max($days,4);$profile='wordpress';}
        if(preg_match('/(e-?commerce|online\s+store|woocommerce|shopify|متجر|سلة|checkout|payment gateway|بوابة دفع)/u',$text)){$days=max($days,6);$difficulty='medium';$profile='ecommerce';}
        if(preg_match('/(chatbot|ai\s+bot|شات بوت|بوت.*ذكاء|customer support ai)/u',$text)){$days=max($days,5);$difficulty='medium';$profile='ai_chatbot';}
        if(preg_match('/(\brag\b|vector|embedding|knowledge\s+base|قاعدة معرفة|llm)/u',$text)){$days=max($days,7);$difficulty='hard';$profile='rag_llm';}
        if(preg_match('/(backend|back-end|laravel|asp\.net|node\.js|django|fastapi|api development|باك اند|باك إند|خلفية)/u',$text)){$days=max($days,5);$difficulty='medium';$profile='backend';}
        if(preg_match('/(full[- ]?stack|platform|saas|dashboard.*admin|منصة.*متكاملة|نظام.*متكامل)/u',$text)){$days=max($days,9);$difficulty='hard';$profile='full_platform';}
        if(preg_match('/(mobile\s+app|flutter|android|ios|تطبيق جوال|تطبيق موبايل)/u',$text)){$days=max($days,8);$difficulty='hard';$profile='mobile_or_backend';}
        $featureAdds=0;
        foreach([
            '/(auth|login|register|roles?|permissions?|تسجيل دخول|صلاحيات)/u'=>1,
            '/(admin\s+panel|dashboard|لوحة تحكم)/u'=>1,
            '/(payment|stripe|paypal|بوابة دفع|دفع إلكتروني|دفع الكتروني)/u'=>1,
            '/(real[- ]?time|websocket|live tracking|google maps|تتبع|خرائط)/u'=>2,
            '/(migration|import|export|ترحيل|استيراد|تصدير)/u'=>1,
            '/(multi[- ]?language|arabic.*english|rtl|متعدد اللغات|لغتين)/u'=>1,
        ] as $re=>$add)if(preg_match($re,$text))$featureAdds+=$add;
        $days=max(1,min(45,$days+$featureAdds));
        if($days<=2)$difficulty='easy';elseif($days>=8)$difficulty='hard';
        $dayCost=self::internalDayCost($currency);
        $riskFactor=match(pb_strtolower((string)($evaluation['risk_level']??''))){'high','critical','مرتفع'=>1.20,'low','منخفض'=>0.95,default=>1.05};
        $cost=max($dayCost,round($days*$dayCost*$riskFactor,2));
        return ['cost'=>$cost,'confidence'=>46,'sample_count'=>0,'source'=>'heuristic_'.$profile,'historical_median'=>null,'estimated_days'=>$days,'difficulty'=>$difficulty,'pricing_currency'=>$currency,'profile'=>$profile,'day_cost'=>$dayCost];
    }

    public static function blend(array $evaluation,array $base,string $currency):array {
        $currency=OpportunityMoney::normalizeCurrency($currency);if($currency==='UNK')$currency=self::internalCurrency();
        $ai=max(0,(float)($evaluation['estimated_cost']??0));
        $heur=self::heuristic($evaluation,$base,$currency);
        $category=self::key((string)($evaluation['category']??''));$type=self::key((string)($evaluation['opportunity_type']??''));
        $samples=[];$actualCount=0;$ownerCount=0;$observationCount=0;
        try{
            $sql="SELECT estimated_cost,owner_cost,actual_cost,source_kind FROM opportunity_cost_observations WHERE currency=? AND (category_key=? OR type_key=?) AND COALESCE(actual_cost,owner_cost,estimated_cost,0)>0 ORDER BY CASE WHEN actual_cost IS NOT NULL AND actual_cost>0 THEN 0 WHEN owner_cost IS NOT NULL AND owner_cost>0 THEN 1 ELSE 2 END,id DESC LIMIT 60";
            $q=db()->prepare($sql);$q->execute([$currency,$category,$type]);
            foreach($q->fetchAll() as $r){
                $v=(float)($r['actual_cost']??0);$weight=1;
                if($v>0){$weight=4;$actualCount++;}
                else{$v=(float)($r['owner_cost']??0);if($v>0){$weight=3;$ownerCount++;}else{$v=(float)($r['estimated_cost']??0);$weight=1;}}
                if($v<=0)continue;$observationCount++;for($i=0;$i<$weight;$i++)$samples[]=$v;
            }
        }catch(Throwable){
            $baseCost=$ai>0?($ai*.65+$heur['cost']*.35):$heur['cost'];
            return $heur+['cost'=>round($baseCost,2),'confidence'=>$ai>0?48:$heur['confidence'],'source'=>$ai>0?'ai_heuristic_blend':$heur['source']];
        }
        $median=$samples?self::median($samples):null;
        if($median===null){
            $baseCost=$ai>0?($ai*.65+$heur['cost']*.35):$heur['cost'];
            return $heur+['cost'=>round($baseCost,2),'confidence'=>$ai>0?50:$heur['confidence'],'source'=>$ai>0?'ai_heuristic_blend':$heur['source']];
        }
        $historyWeight=min(0.72,0.25+min(30,$observationCount)*0.02+$actualCount*0.04+$ownerCount*0.02);
        $starting=$ai>0?($ai*.70+$heur['cost']*.30):$heur['cost'];
        $cost=$starting*(1-$historyWeight)+$median*$historyWeight;
        $detail=((string)($base['_detail_status']??''))==='verified'?8:0;
        $confidence=max(50,min(96,(int)round(45+min(35,$observationCount)*1.5+$actualCount*5+$ownerCount*2+$detail)));
        return $heur+['cost'=>round($cost,2),'confidence'=>$confidence,'sample_count'=>$observationCount,'source'=>$ai>0?'ai_history_heuristic':'history_heuristic','historical_median'=>round((float)$median,2)];
    }

    public static function record(int $opportunityId,array $evaluation,string $currency,array $estimate):void {
        if($opportunityId<1)return;$currency=OpportunityMoney::normalizeCurrency($currency);if($currency==='UNK')return;
        $cost=max(0,(float)($estimate['cost']??$evaluation['estimated_cost']??0));if($cost<=0)return;
        try{
            db()->prepare("INSERT INTO opportunity_cost_observations(opportunity_id,category_key,type_key,difficulty,currency,estimated_cost,source_kind,confidence,sample_count) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE category_key=VALUES(category_key),type_key=VALUES(type_key),difficulty=VALUES(difficulty),currency=VALUES(currency),estimated_cost=VALUES(estimated_cost),source_kind=VALUES(source_kind),confidence=VALUES(confidence),sample_count=VALUES(sample_count),updated_at=NOW()")
                ->execute([$opportunityId,self::key((string)($evaluation['category']??'')),self::key((string)($evaluation['opportunity_type']??'')),(string)($evaluation['difficulty']??'unknown'),$currency,$cost,(string)($estimate['source']??'heuristic'),(int)($estimate['confidence']??0),(int)($estimate['sample_count']??0)]);
        }catch(Throwable){}
    }

    public static function feedback(int $opportunityId,float $amount,string $kind='owner_estimate'):void {
        if($opportunityId<1||$amount<=0)throw new RuntimeException('opportunity_cost_invalid');
        $q=db()->prepare('SELECT id,category,opportunity_type,difficulty,currency,estimated_cost,suggested_offer,cost_estimate_source FROM opportunities WHERE id=?');$q->execute([$opportunityId]);$o=$q->fetch();if(!$o)throw new RuntimeException('opportunity_not_found');
        $currency=OpportunityMoney::normalizeCurrency((string)$o['currency']);
        if($currency==='UNK')$currency=self::currencyFromSource((string)($o['cost_estimate_source']??''))?:self::internalCurrency();
        $kind=$kind==='actual'?'actual':'owner_estimate';$owner=$kind==='owner_estimate'?$amount:null;$actual=$kind==='actual'?$amount:null;
        db()->prepare("INSERT INTO opportunity_cost_observations(opportunity_id,category_key,type_key,difficulty,currency,estimated_cost,owner_cost,actual_cost,source_kind,confidence,sample_count) VALUES (?,?,?,?,?,?,?,?,?,100,0) ON DUPLICATE KEY UPDATE owner_cost=COALESCE(VALUES(owner_cost),owner_cost),actual_cost=COALESCE(VALUES(actual_cost),actual_cost),source_kind=VALUES(source_kind),confidence=100,updated_at=NOW()")
            ->execute([$opportunityId,self::key((string)$o['category']),self::key((string)$o['opportunity_type']),(string)$o['difficulty'],$currency,(float)$o['estimated_cost'],$owner,$actual,$kind]);
        db()->prepare('UPDATE opportunities SET estimated_cost=?,cost_estimate_source=?,cost_confidence=100,projected_profit=GREATEST(0,COALESCE(suggested_offer,0)-?) WHERE id=?')->execute([$amount,$kind==='actual'?'actual_feedback':'owner_feedback',$amount,$opportunityId]);
        Audit::log('owner','1','opportunity.cost_feedback','opportunity',(string)$opportunityId,'verified',null,null,['kind'=>$kind,'amount'=>$amount,'currency'=>$currency]);
    }

    public static function stats(int $opportunityId):array {try{$q=db()->prepare('SELECT * FROM opportunity_cost_observations WHERE opportunity_id=? LIMIT 1');$q->execute([$opportunityId]);return $q->fetch()?:[];}catch(Throwable){return [];}}
    public static function currencyFromSource(string $source):?string {if(preg_match('/(?:pricing|internal|heuristic)_([A-Z]{3})(?:_|$)/i',$source,$m)){ $c=OpportunityMoney::normalizeCurrency($m[1]); return $c==='UNK'?null:$c; }return null;}
    private static function median(array $values):?float {$values=array_values(array_filter(array_map('floatval',$values),static fn($v)=>$v>0));if(!$values)return null;sort($values,SORT_NUMERIC);$n=count($values);$i=intdiv($n,2);return $n%2?$values[$i]:(($values[$i-1]+$values[$i])/2);}
    private static function key(string $s):string{$s=pb_strtolower(trim(preg_replace('/\s+/u',' ',$s)??''));return pb_substr($s!==''?$s:'عام',0,160);}
}
