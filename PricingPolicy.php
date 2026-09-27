<?php
declare(strict_types=1);
final class PricingPolicy {
    public static function values():array{return [
        'budget_min_percent'=>(float)setting('business.price_budget_min_percent','85'),
        'budget_max_percent'=>(float)setting('business.price_budget_max_percent','95'),
        'deposit_start_percent'=>(float)setting('business.deposit_start_percent',setting('business.deposit_percent','40')),
        'deposit_min_percent'=>(float)setting('business.deposit_min_percent','20'),
        'target_margin_percent'=>(float)setting('business.target_profit_margin_percent','45'),
        'minimum_margin_percent'=>(float)setting('opportunities.minimum_verified_margin_percent','30'),
    ];}

    public static function recommend(?float $budgetMin,?float $budgetMax,float $estimatedCost=0,string $risk='medium'):array{
        $p=self::values();$budget=$budgetMax&&$budgetMax>0?$budgetMax:($budgetMin&&$budgetMin>0?$budgetMin:null);
        if(!$budget)return self::recommendFromCost($estimatedCost,$risk,null);
        $lo=$budget*$p['budget_min_percent']/100;$hi=$budget*$p['budget_max_percent']/100;
        $riskAdj=in_array(strtolower($risk),['high','critical'],true)?0.95:(strtolower($risk)==='low'?0.88:0.91);
        $price=max($lo,min($hi,$budget*$riskAdj));
        if($estimatedCost>0){
            $targetMargin=max(.15,min(.80,$p['target_margin_percent']/100));
            $costPrice=$estimatedCost/max(.20,1-$targetMargin);
            $price=max($price,min($hi,$costPrice));
        }
        $margin=$price>0&&$estimatedCost>0?($price-$estimatedCost)/$price:null;
        $ownerRequired=$margin!==null&&$margin<max(.10,min(.80,$p['minimum_margin_percent']/100));
        return ['price'=>round($price,2),'min'=>round($lo,2),'max'=>round($hi,2),'deposit_percent'=>$p['deposit_start_percent'],'owner_required'=>$ownerRequired,'margin'=>$margin,'reason'=>$ownerRequired?'ميزانية العميل لا تحقق هامش الربح الأدنى بالتكلفة الحالية':'ضمن سياسة '.($p['budget_min_percent']).'%–'.($p['budget_max_percent']).'% من ميزانية العميل'];
    }

    /** Pricing for opportunities where the buyer did not publish a budget. */
    public static function recommendFromCost(float $estimatedCost,string $risk='medium',?int $estimatedDays=null):array{
        $p=self::values();if($estimatedCost<=0)return ['price'=>null,'min'=>null,'max'=>null,'deposit_percent'=>$p['deposit_start_percent'],'owner_required'=>true,'margin'=>null,'reason'=>'التكلفة الداخلية غير متاحة'];
        $target=max(.20,min(.75,$p['target_margin_percent']/100));
        if(in_array(strtolower($risk),['high','critical'],true))$target=min(.75,$target+.08);
        elseif(strtolower($risk)==='low')$target=max(.25,$target-.03);
        $price=$estimatedCost/max(.20,1-$target);
        // Keep small jobs commercially worthwhile even when the raw effort estimate is very low.
        $floorMultiplier=$estimatedDays!==null&&$estimatedDays<=2?1.80:1.65;
        $price=max($price,$estimatedCost*$floorMultiplier);
        $price=self::roundCommercial($price);
        $margin=($price-$estimatedCost)/max(1,$price);
        return ['price'=>$price,'min'=>$price,'max'=>$price,'deposit_percent'=>$p['deposit_start_percent'],'owner_required'=>false,'margin'=>$margin,'reason'=>'تسعير داخلي لعدم وجود ميزانية معلنة؛ مستهدف هامش ربح '.(int)round($target*100).'%'];
    }

    public static function depositAllowed(float $percent):bool{return $percent+0.0001>=self::values()['deposit_min_percent'];}
    private static function roundCommercial(float $v):float {if($v>=10000)return round($v/500)*500;if($v>=1000)return round($v/100)*100;if($v>=100)return round($v/10)*10;return round($v,2);}
}
