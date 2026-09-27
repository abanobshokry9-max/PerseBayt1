<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
$providers=db()->query("SELECT provider_key,label,status,enabled FROM providers WHERE kind='ai' ORDER BY enabled DESC,label")->fetchAll();
$key=trim((string)($_GET['provider']??$_POST['provider']??'openrouter'));
$result=null;$error='';$prompt=trim((string)($_POST['prompt']??''));
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{Auth::verifyCsrf();$result=AiGateway::directProviderText($key,$prompt,1200);}
    catch(Throwable $e){$error=Security::redactSecrets($e->getMessage(),350);}
}
AdminUi::header('تجربة دردشة مزود AI','integrations');echo AdminUi::flash();
?>
<div class="grid">
<section class="card span-7"><div class="card-title"><div><h2>اختبار دردشة مباشر</h2><span class="muted small">يختبر المزود المحدد فقط، ولا يرسل رسائل للعملاء ولا ينفذ أدوات خارجية.</span></div><a class="link" href="integrations.php">رجوع للمزودات</a></div>
<form method="post"><?=AdminUi::csrf()?><div class="field"><label>المزود</label><select name="provider"><?php foreach($providers as $p):?><option value="<?=e($p['provider_key'])?>" <?=$key===$p['provider_key']?'selected':''?>><?=e($p['label'])?><?=empty($p['enabled'])?' — موقوف':''?></option><?php endforeach?></select></div><div class="field"><label>رسالة الاختبار</label><textarea name="prompt" rows="7" required placeholder="مثال: اشرح في سطرين وظيفة هذا المزود داخل Company OS."><?=e($prompt)?></textarea></div><button class="btn">إرسال للمزود المحدد</button></form>
</section>
<section class="card span-5"><div class="card-title"><h2>النتيجة</h2></div><?php if($error!==''):?><div class="notice error"><?=e(AdminUi::humanError($error))?> <span class="mono small"><?=e($error)?></span></div><?php elseif($result):?><div class="settings-note"><b>Model:</b> <?=e((string)($result['model']??'—'))?></div><div class="subtle" style="white-space:pre-wrap;line-height:1.9;margin-top:12px"><?=e((string)($result['text']??''))?></div><?php else:?><p class="muted">اكتب رسالة واضغط إرسال. النتيجة ستظهر هنا بدون تغيير إعدادات الوكلاء.</p><?php endif?></section>
</div>
<?php AdminUi::footer();
