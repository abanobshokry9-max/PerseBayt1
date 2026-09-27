<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
$agents=AgentService::all();
$messages=TeamChatService::recent(100);
AdminUi::header('شات الفريق','team_chat');echo AdminUi::flash();
?>
<section class="card team-chat-shell">
  <div class="card-title team-chat-title">
    <div><h2>أبانوب + رامي + وليد + أيمن + عماد</h2></div>
    <div class="team-presence"><?php foreach($agents as $a):?><span class="team-person"><i class="dot <?=in_array($a['status'],['online','working','idle'],true)?'ok':'warn'?>"></i><?=e($a['display_name'])?></span><?php endforeach?></div>
  </div>
  <div class="chat-box team-chat-box" data-team-chat><?php if(!$messages):?><?=AdminUi::empty('لا توجد رسائل بعد.')?><?php else:foreach($messages as $m)echo TeamChatService::bubble($m);endif?></div>
  <form class="chat-form team-chat-form" data-team-chat-form><?=AdminUi::csrf()?><textarea name="message" placeholder="اكتب للفريق أو ابدأ باسم الوكيل..."></textarea><button class="btn">إرسال</button></form>
</section>
<div class="home-card-grid compact-grid team-agent-cards">
  <?php foreach($agents as $a):?><a class="home-action-card" href="agent-profile.php?slug=<?=e($a['slug'])?>"><b><?=e($a['display_name'])?></b><span><?=e($a['role_title'])?></span></a><?php endforeach?>
</div>
<?php AdminUi::footer();
