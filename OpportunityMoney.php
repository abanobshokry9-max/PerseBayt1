<?php
declare(strict_types=1);

/**
 * Extracts money evidence from the source text without doing FX conversion.
 * A currency is only trusted when it is explicitly present in the source.
 */
final class OpportunityMoney {
    private const CURRENCIES = [
        'USD'=>['/(?:\bUSD\b|US\$|\$|دولار(?:\s+أمريكي|\s+امريكي)?)/iu','USD / $'],
        'EUR'=>['/(?:\bEUR\b|€|يورو)/iu','EUR / €'],
        'GBP'=>['/(?:\bGBP\b|£|جنيه\s+(?:إسترليني|استرليني))/iu','GBP / £'],
        'EGP'=>['/(?:\bEGP\b|ج\s*\.?\s*م\.?|جنيه\s+مصري|جنيه\s+مصر(?:ي|ية))/iu','EGP / ج.م'],
        'SAR'=>['/(?:\bSAR\b|ر\s*\.?\s*س\.?|ريال\s+سعودي)/iu','SAR / ر.س'],
        'AED'=>['/(?:\bAED\b|د\s*\.?\s*[إا]\.?|درهم\s+(?:إماراتي|اماراتي))/iu','AED / د.إ'],
        'KWD'=>['/(?:\bKWD\b|د\s*\.?\s*ك\.?|دينار\s+كويتي)/iu','KWD / د.ك'],
        'QAR'=>['/(?:\bQAR\b|ر\s*\.?\s*ق\.?|ريال\s+قطري)/iu','QAR / ر.ق'],
    ];

    public static function normalizeCurrency(string $currency):string {
        $c=strtoupper(trim($currency));
        if($c==='') return 'UNK';
        $aliases=['LE'=>'EGP','L.E'=>'EGP','US$'=>'USD','$'=>'USD','€'=>'EUR','£'=>'GBP'];
        if(isset($aliases[$c])) $c=$aliases[$c];
        return array_key_exists($c,self::CURRENCIES)?$c:'UNK';
    }

    public static function evidence(array $base):array {
        $text=trim((string)($base['title']??'').' '.(string)($base['snippet']??''));
        $found=[];$evidence=[];
        foreach(self::CURRENCIES as $code=>$def){
            if(preg_match($def[0],$text,$m)){
                $found[$code]=true;
                $evidence[]=$code.': '.pb_substr(trim((string)$m[0]),0,24);
            }
        }
        $codes=array_keys($found);
        $conflict=count($codes)>1;
        $currency=count($codes)===1?$codes[0]:'UNK';
        $raw=$currency!=='UNK'?self::budgetText($text,$currency):'';
        $amounts=($currency!=='UNK'&&$raw!=='')?self::amountsForCurrency($raw,$currency):[];
        $budgetMin=null;$budgetMax=null;
        if($amounts){
            $amounts=array_values(array_filter($amounts,static fn($n)=>is_finite($n)&&$n>0));
            if($amounts){$budgetMin=min($amounts);$budgetMax=max($amounts);if(count($amounts)===1)$budgetMax=$budgetMin;}
        }
        return [
            'currency'=>$currency,
            'confidence'=>$currency==='UNK'?0:($conflict?0:98),
            'conflict'=>$conflict,
            'evidence'=>implode(' | ',$evidence),
            'raw'=>$raw,
            'budget_min'=>$budgetMin,
            'budget_max'=>$budgetMax,
            'amount_count'=>count($amounts),
        ];
    }


    private static function budgetText(string $text,string $currency):string {
        $tokens=match($currency){
            'USD'=>'(?:USD|US\$|\$|دولار(?:\s+أمريكي|\s+امريكي)?)','EUR'=>'(?:EUR|€|يورو)','GBP'=>'(?:GBP|£|جنيه\s+(?:إسترليني|استرليني))',
            'EGP'=>'(?:EGP|ج\s*\.?\s*م\.?|جنيه\s+مصري)','SAR'=>'(?:SAR|ر\s*\.?\s*س\.?|ريال\s+سعودي)',
            'AED'=>'(?:AED|د\s*\.?\s*[إا]\.?|درهم\s+(?:إماراتي|اماراتي))','KWD'=>'(?:KWD|د\s*\.?\s*ك\.?|دينار\s+كويتي)',
            'QAR'=>'(?:QAR|ر\s*\.?\s*ق\.?|ريال\s+قطري)',default=>'',
        };
        if($tokens==='')return '';
        $num='[0-9][0-9,.\s]{0,18}[0-9]|[0-9]';
        $amount='(?:'.$tokens.'\s*(?:'.$num.')|(?:'.$num.')\s*'.$tokens.')';
        $range='(?:'.$tokens.'\s*(?:'.$num.')\s*(?:-|–|—|to|إلى|الى)\s*(?:'.$tokens.'\s*)?(?:'.$num.')(?:\s*'.$tokens.')?|(?:'.$num.')\s*(?:'.$tokens.'\s*)?(?:-|–|—|to|إلى|الى)\s*(?:'.$tokens.'\s*)?(?:'.$num.')\s*'.$tokens.')';
        $budget='(?:budget|project\s+budget|fixed\s+price|estimated\s+budget|ميزانية|الميزانية|قيمة\s+المشروع|ميزانية\s+المشروع|budget\s+estim[eé]|presupuesto)';
        $patterns=[
            '/'.$budget.'[^\n\r]{0,90}?('.$range.'|'.$amount.')/iu',
            '/('.$range.'|'.$amount.')[^\n\r]{0,60}?'.$budget.'/iu',
            '/('.$range.')/iu',
            '/('.$amount.')/iu',
        ];
        foreach($patterns as $re){
            if(preg_match($re,$text,$m)){
                $raw=trim((string)($m[1]??$m[0]));
                $raw=preg_replace('/\s+/u',' ',$raw)?:$raw;
                return pb_substr($raw,0,180);
            }
        }
        return '';
    }

    private static function amountsForCurrency(string $text,string $currency):array {
        $tokens=match($currency){
            'USD'=>'(?:USD|US\$|\$|دولار(?:\s+أمريكي|\s+امريكي)?)',
            'EUR'=>'(?:EUR|€|يورو)',
            'GBP'=>'(?:GBP|£|جنيه\s+(?:إسترليني|استرليني))',
            'EGP'=>'(?:EGP|ج\s*\.?\s*م\.?|جنيه\s+مصري)',
            'SAR'=>'(?:SAR|ر\s*\.?\s*س\.?|ريال\s+سعودي)',
            'AED'=>'(?:AED|د\s*\.?\s*[إا]\.?|درهم\s+(?:إماراتي|اماراتي))',
            'KWD'=>'(?:KWD|د\s*\.?\s*ك\.?|دينار\s+كويتي)',
            'QAR'=>'(?:QAR|ر\s*\.?\s*ق\.?|ريال\s+قطري)',
            default=>'',
        };
        if($tokens==='')return [];
        $number='([0-9][0-9,\.\s]{0,18}[0-9]|[0-9])';
        $patterns=[
            // Ranges with one currency token: 15000 - 25000 EGP / EGP 15000 - 25000
            '/'.$number.'\s*(?:-|–|—|to|إلى|الى)\s*'.$number.'\s*'.$tokens.'/iu',
            '/'.$tokens.'\s*'.$number.'\s*(?:-|–|—|to|إلى|الى)\s*'.$number.'/iu',
            '/'.$tokens.'\s*'.$number.'/iu',
            '/'.$number.'\s*'.$tokens.'/iu',
        ];
        $out=[];
        foreach($patterns as $pattern){
            if(preg_match_all($pattern,$text,$matches,PREG_SET_ORDER)){
                foreach($matches as $m){
                    for($i=1;$i<count($m);$i++){
                        if(!isset($m[$i])||!preg_match('/[0-9]/',(string)$m[$i]))continue;
                        $n=self::number((string)$m[$i]);if($n!==null)$out[]=$n;
                    }
                }
            }
        }
        // Keep source order but drop duplicates caused by prefix/suffix passes.
        $seen=[];$clean=[];foreach($out as $n){$k=sprintf('%.4F',$n);if(isset($seen[$k]))continue;$seen[$k]=true;$clean[]=$n;if(count($clean)>=6)break;}
        return $clean;
    }

    private static function number(string $raw):?float {
        $s=preg_replace('/\s+/u','',trim($raw));
        if($s==='')return null;
        // Commas between 3-digit groups are thousands separators in the supported marketplaces.
        if(preg_match('/^[0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]+)?$/',$s))$s=str_replace(',','',$s);
        elseif(substr_count($s,',')===1&&!str_contains($s,'.')){
            [$a,$b]=explode(',',$s,2);$s=strlen($b)===3?$a.$b:$a.'.'.$b;
        }else $s=str_replace(',','',$s);
        return is_numeric($s)?(float)$s:null;
    }
}
