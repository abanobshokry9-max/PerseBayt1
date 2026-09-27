<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();

$sessionId=(int)($_GET['session']??0);
$type=(string)($_GET['type']??'');
$customerFilter=(int)($_GET['customer']??0);
$search=trim((string)($_GET['q']??''));
$allowedTypes=['owner','customer','agent_pair'];
if(!in_array($type,$allowedTypes,true))$type='';

$select="SELECT s.*,a.display_name last_agent,p.name project_name,t.title task_title,
 (SELECT COUNT(*) FROM messages m WHERE m.session_id=s.id) message_count,
 (SELECT m.body_text FROM messages m WHERE m.session_id=s.id ORDER BY m.id DESC LIMIT 1) last_message,
 (SELECT m.channel_key FROM messages m WHERE m.session_id=s.id ORDER BY m.id DESC LIMIT 1) last_message_channel,
 x.customer_id,x.channel_key latest_channel,x.provider latest_provider,x.external_thread_id,
 c.display_name customer_name,c.phone customer_phone,c.status customer_status,c.email customer_email,
 (SELECT cw.external_thread_id FROM conversations cw WHERE cw.session_id=s.id AND cw.channel_key='whatsapp' AND cw.external_thread_id IS NOT NULL AND cw.external_thread_id<>'' ORDER BY cw.id DESC LIMIT 1) whatsapp_thread
 FROM conversation_sessions s
 LEFT JOIN agents a ON a.id=s.last_agent_id
 LEFT JOIN projects p ON p.id=s.active_project_id
 LEFT JOIN tasks t ON t.id=s.active_task_id
 LEFT JOIN conversations x ON x.id=(SELECT xx.id FROM conversations xx WHERE xx.session_id=s.id ORDER BY xx.id DESC LIMIT 1)
 LEFT JOIN customers c ON c.id=x.customer_id";
$where=[];$args=[];
if($type!==''){$where[]='s.subject_type=?';$args[]=$type;}
if($customerFilter>0){$where[]='EXISTS(SELECT 1 FROM conversations cf WHERE cf.session_id=s.id AND cf.customer_id=?)';$args[]=$customerFilter;}
$sql=$select.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY s.last_activity_at DESC LIMIT 250';
$q=db()->prepare($sql);$q->execute($args);$sessions=$q->fetchAll();

if($search!==''){
    $needle=pb_strtolower($search);
    $sessions=array_values(array_filter($sessions,static function(array $s)use($needle):bool{
        $hay=pb_strtolower(implode(' ',[(string)($s['customer_name']??''),(string)($s['customer_phone']??''),(string)($s['whatsapp_thread']??''),(string)($s['subject_key']??''),(string)($s['project_name']??''),(string)($s['last_message']??'')]));
        return pb_strpos($hay,$needle)!==false;
    }));
}

$selected=null;$messages=[];$pending=null;$waPending=[];
if($sessionId>0){
    $q=db()->prepare($select.' WHERE s.id=? LIMIT 1');$q->execute([$sessionId]);$selected=$q->fetch()?:null;
    if($selected){
        $q=db()->prepare("SELECT m.*,a.display_name agent_name,cu.display_name customer_name,cu.phone stored_customer_phone,cx.external_thread_id,CASE WHEN cx.channel_key='whatsapp' AND cx.external_thread_id IS NOT NULL AND cx.external_thread_id<>'' THEN cx.external_thread_id ELSE cu.phone END customer_phone FROM messages m LEFT JOIN agents a ON m.sender_type='agent' AND a.slug=m.sender_ref LEFT JOIN conversations cx ON cx.id=m.conversation_id LEFT JOIN customers cu ON cu.id=cx.customer_id WHERE m.session_id=? ORDER BY m.id ASC LIMIT 500");
        $q->execute([$sessionId]);$messages=$q->fetchAll();
        $q=db()->prepare("SELECT * FROM pending_actions WHERE session_id=? AND state='pending' AND expires_at>NOW() ORDER BY id DESC LIMIT 1");$q->execute([$sessionId]);$pending=$q->fetch()?:null;
        if(!empty($selected['customer_id'])){try{$waPending=WhatsAppPolicy::pending((int)$selected['customer_id'],10);}catch(Throwable){$waPending=[];}}
    }
}

$phoneFor=static function(array $s):string{
    $p=ConversationService::normalizePhone((string)($s['whatsapp_thread']??''));
    if($p==='')$p=ConversationService::normalizePhone((string)($s['customer_phone']??''));
    return $p;
};
$titleFor=static function(array $s):string{
    if(($s['subject_type']??'')==='owner')return 'أبانوب ورامي';
    if(($s['subject_type']??'')==='customer')return trim((string)($s['customer_name']??''))?:('عميل #'.(int)($s['customer_id']??0));
    return 'محادثة الوكلاء · '.(string)($s['subject_key']??'');
};

AdminUi::header('واتساب والمحادثات','communications');echo AdminUi::flash();
?>
<section class="communications-toolbar card">
  <form method="get" class="communications-search"><input type="search" name="q" value="<?=e($search)?>" placeholder="ابحث بالاسم أو الرقم أو المشروع..."><?php if($type!==''):?><input type="hidden" name="type" value="<?=e($type)?>"><?php endif?><button class="btn">بحث</button></form>
  <div class="filter-chips"><a class="<?=($type===''?'active':'')?>" href="communications.php">الكل</a><a class="<?=($type==='customer'?'active':'')?>" href="?type=customer">العملاء</a><a class="<?=($type==='owner'?'active':'')?>" href="?type=owner">رامي</a><a class="<?=($type==='agent_pair'?'active':'')?>" href="?type=agent_pair">الوكلاء</a></div>
</section>

<div class="conversation-card-grid">
<?php foreach($sessions as $s):$phone=$phoneFor($s);$isCustomer=$s['subject_type']==='customer';?>
  <article class="conversation-card">
    <div class="conversation-card-head"><div><span class="conversation-type"><?=e(AdminUi::label((string)$s['subject_type']))?></span><h2><?=e($titleFor($s))?></h2></div><?=AdminUi::badge((string)$s['status'])?></div>
    <?php if($isCustomer):?><div class="customer-phone-row"><span>رقم العميل</span><b dir="ltr"><?=e($phone!==''?'+'.$phone:'غير متاح')?></b></div><?php endif?>
    <div class="conversation-meta-grid"><span><b>القناة</b><?=e(AdminUi::channel((string)($s['last_message_channel']?:$s['latest_channel']?:'dashboard')))?></span><span><b>المشروع</b><?=e($s['project_name']?:'—')?></span><span><b>الرسائل</b><?=(int)$s['message_count']?></span><span><b>آخر نشاط</b><?=e(AdminUi::date($s['last_activity_at']))?></span></div>
    <p class="conversation-preview"><?=e(pb_substr(trim((string)($s['last_message']??''))?:'لا توجد رسائل بعد.',0,180))?></p>
    <div class="conversation-card-actions"><a class="btn" href="communications.php?session=<?=(int)$s['id']?><?=($type!==''?'&type='.urlencode($type):'')?>">فتح المحادثة</a><?php if($isCustomer&&$s['customer_id']):?><a class="btn secondary" href="customers.php?id=<?=(int)$s['customer_id']?>">بيانات العميل</a><?php endif?></div>
  </article>
<?php endforeach?>
<?php if(!$sessions):?><?=AdminUi::empty('لا توجد محادثات مطابقة.')?><?php endif?>
</div>

<?php if($selected):$selectedPhone=$phoneFor($selected);$isCustomer=$selected['subject_type']==='customer';?>
<div class="conversation-modal" role="dialog" aria-modal="true" aria-label="المحادثة">
  <a class="conversation-modal-backdrop" href="communications.php<?=($type!==''?'?type='.urlencode($type):'')?>" aria-label="إغلاق"></a>
  <section class="conversation-modal-panel">
    <div class="conversation-modal-head">
      <div><span class="conversation-type"><?=e(AdminUi::label((string)$selected['subject_type']))?></span><h2><?=e($titleFor($selected))?></h2><?php if($isCustomer):?><b class="modal-phone" dir="ltr"><?=e($selectedPhone!==''?'+'.$selectedPhone:'رقم غير متاح')?></b><?php endif?></div>
      <div class="actions"><a class="icon-close" href="communications.php<?=($type!==''?'?type='.urlencode($type):'')?>" aria-label="إغلاق">×</a></div>
    </div>

    <div class="conversation-controlbar">
      <?=AdminUi::badge((string)$selected['status'])?>
      <?php if($isCustomer&&$selected['customer_status']):?><?=AdminUi::badge((string)$selected['customer_status'])?><?php endif?>
      <?php if($selectedPhone!==''&&$isCustomer):?><a class="btn secondary small-btn" target="_blank" rel="noopener" href="https://wa.me/<?=e($selectedPhone)?>">فتح واتساب</a><?php endif?>
      <?php if($selected['status']==='open'):?><form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="conversation_close"><input type="hidden" name="session_id" value="<?=(int)$selected['id']?>"><input type="hidden" name="return" value="/api/admin/communications.php?session=<?=(int)$selected['id']?>"><button class="btn secondary small-btn">إغلاق</button></form><?php elseif(!$isCustomer||$selected['customer_status']!=='blocked'):?><form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="conversation_reopen"><input type="hidden" name="session_id" value="<?=(int)$selected['id']?>"><input type="hidden" name="return" value="/api/admin/communications.php?session=<?=(int)$selected['id']?>"><button class="btn secondary small-btn">إعادة فتح</button></form><?php endif?>
      <?php if($isCustomer&&$selected['customer_id']):?><?php if($selected['customer_status']==='blocked'):?><form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="conversation_unblock"><input type="hidden" name="session_id" value="<?=(int)$selected['id']?>"><input type="hidden" name="return" value="/api/admin/communications.php?session=<?=(int)$selected['id']?>"><button class="btn secondary small-btn">رفع الحظر</button></form><?php else:?><form method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="conversation_block"><input type="hidden" name="session_id" value="<?=(int)$selected['id']?>"><input type="hidden" name="return" value="/api/admin/communications.php?session=<?=(int)$selected['id']?>"><button class="btn danger small-btn" data-confirm="حظر العميل وإيقاف المحادثة؟">حظر</button></form><?php endif?><?php endif?>
    </div>

    <div class="conversation-context-bar"><span><?=e($selected['project_name']?:'بدون مشروع')?></span><span><?=e($selected['task_title']?:'بدون مهمة نشطة')?></span><span><?=e($selected['last_agent']?:'رامي')?></span></div>
    <?php if($pending):$pp=json_decode((string)$pending['plan_json'],true)?:[];?><div class="pending-compact"><?=AdminUi::badge((string)$pending['risk'])?><span><?=e(pb_substr((string)($pp['summary_to_owner']??$pp['request']??$pending['action_type']),0,220))?></span></div><?php endif?>
    <?php if($waPending):?><div class="pending-compact"><?=AdminUi::badge('warning')?><span>فيه <?=count($waPending)?> رسالة WhatsApp محفوظة. لو نافذة 24 ساعة مقفولة، النظام يبدأ بقالب Approved ثم يرسل الرسائل تلقائيًا بعد رد العميل.</span></div><?php endif?>

    <div class="chat-box conversation-modal-chat" <?=($selected['subject_type']==='owner'?'data-chat':'')?>><?php foreach($messages as $m)echo AdminUi::messageBubble($m);?><?php if(!$messages)echo AdminUi::empty('لا توجد رسائل بعد.');?></div>

    <?php if($selected['subject_type']==='owner'):?><form class="chat-form" data-chat-form><?=AdminUi::csrf()?><textarea name="message" placeholder="اكتب لرامي..."></textarea><button class="btn">إرسال</button></form>
    <?php elseif($isCustomer&&$selected['customer_id']&&$selected['customer_status']!=='blocked'):?><form class="chat-form" method="post" action="action.php"><?=AdminUi::csrf()?><input type="hidden" name="action" value="customer_message"><input type="hidden" name="customer_id" value="<?=(int)$selected['customer_id']?>"><input type="hidden" name="return" value="/api/admin/communications.php?session=<?=(int)$selected['id']?>"><textarea name="body" required placeholder="رسالة للعميل..."></textarea><button class="btn">إرسال بواسطة رامي</button></form><?php endif?>
  </section>
</div>
<?php endif?>
<?php AdminUi::footer();
