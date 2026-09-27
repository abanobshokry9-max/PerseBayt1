<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/public_html/api/src/bootstrap.php';
$out=['ok'=>true,'checks'=>[],'schema_issues'=>[]];
$check=static function(string $k,bool $ok,string $note='')use(&$out):void{$out['checks'][$k]=['ok'=>$ok,'note'=>$note];if(!$ok)$out['ok']=false;};
try{
    $pdo=db();
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='agent_memory_bank' AND COLUMN_NAME='salience'");
    $check('agent_memory_bank.salience',(int)$q->fetchColumn()===1,'canonical priority column');
    $src=(string)file_get_contents(PB_ROOT.'/public_html/api/src/SystemDoctor.php');
    $check('doctor_uses_salience',str_contains($src,"'agent_memory_bank'=>['memory_kind','body_text','embedding_json','confidence','salience','last_used_at']"),'System Doctor matches canonical schema');
    $check('doctor_does_not_require_importance',!str_contains($src,"'agent_memory_bank'=>['memory_kind','body_text','embedding_json','confidence','importance','last_used_at']"),'no false importance requirement');
    $issues=SystemDoctor::schemaIssues();$out['schema_issues']=$issues;
    $bad=array_values(array_filter($issues,static fn($x)=>$x==='missing_column:agent_memory_bank.importance'));
    $check('false_mismatch_removed',$bad===[],$bad?'still present':'removed');
    $check('release_hotfix',ReleaseInfo::HOTFIX==='R2','R2');
}catch(Throwable $e){$out['ok']=false;$out['error']=Security::redactSecrets($e->getMessage(),500);}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($out['ok']?0:2);
