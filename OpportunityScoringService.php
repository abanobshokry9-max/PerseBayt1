<?php
declare(strict_types=1);
final class OpportunityScoringService {
    public const VERSION=3;
    public static function score(array $evaluation,array $base,array $contacts,array $costInfo):array {
        $b=(array)($evaluation['score_breakdown']??[]);
        $published=trim((string)($evaluation['published_at']??$base['published_at']??''));$freshness=self::freshness($published);
        $budgetMin=is_numeric($evaluation['budget_min']??null)?(float)$evaluation['budget_min']:null;$budgetMax=is_numeric($evaluation['budget_max']??null)?(float)$evaluation['budget_max']:null;
        $budget=($budgetMax!==null&&$budgetMax>0)?96:(($budgetMin!==null&&$budgetMin>0)?88:35);
        $cost=max(0,(float)($costInfo['cost']??$evaluation['estimated_cost']??0));$offer=is_numeric($evaluation['suggested_offer']??null)?max(0,(float)$evaluation['suggested_offer']):0;
        $margin=$offer>0&&$cost>0?max(-1,min(1,($offer-$cost)/$offer)):null;
        $profit=$margin===null?42:(int)round(max(0,min(100,35+$margin*120)));
        $difficulty=(string)($evaluation['difficulty']??'unknown');$execution=match($difficulty){'easy'=>96,'medium'=>78,'hard'=>40,default=>58};
        $clarity=self::n($b['requirements_clarity']??self::clarityFromText((string)($base['snippet']??'')));
        $trust=self::n($b['client_trust']??48);$communication=OpportunityContact::directScore($contacts);
        $competition=self::competition((string)($base['snippet']??''),self::n($b['competition']??55));
        $risk=pb_strtolower((string)($evaluation['risk_level']??'unknown'));$riskSafety=(str_contains($risk,'low')||str_contains($risk,'منخفض'))?92:((str_contains($risk,'high')||str_contains($risk,'مرتفع'))?25:62);
        $days=is_numeric($evaluation['estimated_days']??null)?max(1,(int)$evaluation['estimated_days']):(int)($costInfo['estimated_days']??0);$duration=$days===0?55:($days<=2?100:($days<=5?90:($days<=10?76:($days<=20?55:30))));
        $source=self::sourceQuality($evaluation,$base,$b);$intent=self::n($evaluation['intent_confidence']??self::intentFromBase($base));$verified=((string)($base['_detail_status']??''))==='verified'?100:55;
        $weights=['profit'=>22,'budget'=>12,'execution_ease'=>12,'requirements_clarity'=>9,'buyer_intent'=>9,'freshness'=>8,'client_trust'=>7,'competition'=>5,'communication'=>5,'source_quality'=>4,'duration'=>3,'risk_safety'=>2,'source_verification'=>2];
        $parts=['profit'=>$profit,'budget'=>$budget,'execution_ease'=>$execution,'requirements_clarity'=>$clarity,'buyer_intent'=>$intent,'freshness'=>$freshness,'client_trust'=>$trust,'competition'=>$competition,'communication'=>$communication,'source_quality'=>$source,'duration'=>$duration,'risk_safety'=>$riskSafety,'source_verification'=>$verified];
        $sum=0.0;$total=0;foreach($weights as $k=>$w){$sum+=$parts[$k]*$w;$total+=$w;}$score=(int)round($sum/max(1,$total));
        // Commercial caps stop vague/unknown-budget work from outranking verified profitable work.
        if($budget<=35&&$communication<40)$score=min($score,74);
        if($verified<80)$score=min($score,78);
        $minimumMargin=max(0.0,min(0.90,(float)setting('opportunities.minimum_verified_margin_percent','30')/100));
        if($margin!==null&&$margin<$minimumMargin)$score=min($score,58);
        if($difficulty==='hard'&&$budget<=35)$score=min($score,60);
        $out=[];foreach($parts as $k=>$v)$out[$k]=['score'=>$v,'weight'=>$weights[$k]];
        return ['score'=>max(0,min(100,$score)),'version'=>self::VERSION,'breakdown'=>$out,'margin'=>$margin];
    }
    private static function n($v):int{return max(0,min(100,(int)$v));}
    private static function freshness(string $date):int {if($date==='')return 45;$ts=strtotime($date);if(!$ts)return 45;$hours=max(0,(time()-$ts)/3600);return match(true){$hours<=12=>100,$hours<=24=>96,$hours<=72=>90,$hours<=168=>80,$hours<=360=>64,$hours<=720=>48,default=>25};}
    private static function clarityFromText(string $text):int {$n=0;foreach(['budget','ميزانية','deadline','مدة التنفيذ','requirements','المطلوب','deliverables','المخرجات','scope','التفاصيل','API','WordPress','Laravel','n8n'] as $x)if(stripos($text,$x)!==false)$n++;return min(90,40+$n*7);}
    private static function intentFromBase(array $base):int {$text=pb_strtolower((string)($base['title']??'').' '.(string)($base['snippet']??''));$score=35;if(preg_match('/(مطلوب|أبحث عن|احتاج|أحتاج|looking for|need(?:ed)?|seeking|hiring|required)/u',$text))$score+=30;if(preg_match('/(ميزانية|budget|fixed price|proposal|العروض|مدة التنفيذ|deadline)/u',$text))$score+=20;if(((string)($base['_detail_status']??''))==='verified')$score+=10;return min(100,$score);}
    private static function sourceQuality(array $evaluation,array $base,array $b):int {$ai=self::n($evaluation['source_quality']??$b['source_quality']??0);$host=pb_strtolower((string)(parse_url((string)($base['url']??''),PHP_URL_HOST)?:''));$known=match(true){str_contains($host,'upwork.com')=>94,str_contains($host,'freelancer.com')=>88,str_contains($host,'mostaql.com')=>90,str_contains($host,'nafezly.com')=>86,str_contains($host,'peopleperhour.com')=>86,str_contains($host,'workana.com')=>82,str_contains($host,'guru.com')=>80,str_contains($host,'khamsat.com')=>78,default=>55};return max($known,$ai);}
    private static function competition(string $text,int $fallback):int {$low=pb_strtolower($text);if(preg_match('/(?:proposals?|العروض|المتقدمين)\D{0,20}(50\+|[5-9][0-9]|[1-9][0-9]{2,})/u',$low))return 30;if(preg_match('/(?:proposals?|العروض|المتقدمين)\D{0,20}(2[0-9]|3[0-9]|4[0-9])/u',$low))return 50;if(preg_match('/(?:proposals?|العروض|المتقدمين)\D{0,20}([0-9]|1[0-9])/u',$low))return 82;return $fallback;}
}
