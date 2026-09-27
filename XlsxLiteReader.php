<?php
declare(strict_types=1);

/** Minimal XLSX reader for table-style exports. It intentionally ignores the worksheet dimension tag because
 * some TikTok exports declare A1 while containing hundreds of rows. It never evaluates formulas or macros. */
final class XlsxLiteReader {
    public static function rows(string $path,int $maxRows=10000,int $maxCols=120): array {
        if(!is_file($path))throw new RuntimeException('xlsx_file_missing');
        $close=null;
        if(class_exists('ZipArchive')){
            $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('xlsx_open_failed');
            $get=static function(string $name)use($zip){$v=$zip->getFromName($name);return is_string($v)?$v:null;};$close=static fn()=>$zip->close();
        }elseif(class_exists('PharData')){
            try{$phar=new PharData($path);}catch(Throwable $e){throw new RuntimeException('xlsx_open_failed:'.$e->getMessage());}
            $get=static function(string $name)use($phar){try{return isset($phar[$name])?$phar[$name]->getContent():null;}catch(Throwable){return null;}};
        }else throw new RuntimeException('xlsx_zip_reader_required');
        try{
            $shared=self::sharedStrings($get);$sheet=$get('xl/worksheets/sheet1.xml');if(!is_string($sheet)||$sheet==='')throw new RuntimeException('xlsx_sheet_missing');
            $rows=[];$seen=0;if(!preg_match_all('/<row\b[^>]*>(.*?)<\/row>/si',$sheet,$matches))return [];
            foreach($matches[1] as $rowXml){if(++$seen>$maxRows)break;$row=[];
                if(preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/si',$rowXml,$cells,PREG_SET_ORDER))foreach($cells as $cell){$attrs=$cell[1];$body=$cell[2];$ref='';if(preg_match('/\br="([A-Z]+)[0-9]+"/i',$attrs,$m))$ref=strtoupper($m[1]);if($ref==='')continue;$idx=self::colIndex($ref);if($idx<0||$idx>=$maxCols)continue;$type='';if(preg_match('/\bt="([^"]+)"/i',$attrs,$m))$type=$m[1];$value='';
                    if($type==='inlineStr')$value=self::extractText($body);elseif(preg_match('/<v>(.*?)<\/v>/si',$body,$m)){$raw=html_entity_decode(strip_tags($m[1]),ENT_QUOTES|ENT_XML1,'UTF-8');$value=$type==='s'&&ctype_digit(trim($raw))?($shared[(int)$raw]??''):$raw;}elseif(str_contains($body,'<is'))$value=self::extractText($body);$row[$idx]=self::clean($value);
                }
                if($row){$last=max(array_keys($row));$dense=array_fill(0,$last+1,'');foreach($row as $i=>$v)$dense[$i]=$v;$rows[]=$dense;}
            }return $rows;
        }finally{if($close)$close();}
    }
    private static function sharedStrings(callable $get):array{$xml=$get('xl/sharedStrings.xml');if(!is_string($xml)||$xml==='')return [];$out=[];if(preg_match_all('/<si\b[^>]*>(.*?)<\/si>/si',$xml,$m))foreach($m[1] as $si)$out[]=self::extractText($si);return $out;}
    private static function extractText(string $xml):string{$parts=[];if(preg_match_all('/<t\b[^>]*>(.*?)<\/t>/si',$xml,$m))foreach($m[1] as $x)$parts[]=html_entity_decode(strip_tags($x),ENT_QUOTES|ENT_XML1,'UTF-8');return self::clean(implode('',$parts));}
    private static function colIndex(string $letters):int{$n=0;foreach(str_split(strtoupper($letters)) as $ch){$o=ord($ch)-64;if($o<1||$o>26)return -1;$n=$n*26+$o;}return $n-1;}
    private static function clean(string $v):string{$v=str_replace(["\xC2\xA0","\r"],[' ',''],$v);return trim($v);}
}
