<?php
declare(strict_types=1);
final class AdminUi {
    private static array $navGroups=[
        'القيادة'=>[
            'dashboard'=>['/api/admin/','نظرة عامة','⌂'],
            'notifications'=>['/api/admin/notifications.php','الإشعارات','🔔'],
            'team_chat'=>['/api/admin/team-chat.php','شات الفريق','✉'],
        ],
        'المبيعات والفرص'=>[
            'opportunity_hunt'=>['/api/admin/opportunity-hunt.php','صيد المشاريع','⌕'],
            'prospecting'=>['/api/admin/prospecting.php','Company Prospecting','◎'],
            'orders'=>['/api/admin/orders.php','تقييم الفرص','▣'],
            'sales'=>['/api/admin/sales.php','العروض والتفاوض','⌁'],
            'customers'=>['/api/admin/customers.php','العملاء','♙'],
            'payments'=>['/api/admin/payments.php','المالية والدفعات','¤'],
        ],
        'التنفيذ والمشاريع'=>[
            'projects'=>['/api/admin/projects.php','المشاريع','◫'],
            'tasks'=>['/api/admin/tasks.php','المهام','✓'],
            'queue'=>['/api/admin/queue.php','طابور التنفيذ الحي','⇄'],
            'qa'=>['/api/admin/qa.php','مراجعات عماد','◈'],
            'security_tests'=>['/api/admin/security-tests.php','الاختبارات','🛡'],
            'agency'=>['/api/admin/agency.php','وكالة الفارس','◆'],
            'creator_intelligence'=>['/api/admin/creator-intelligence.php','ذكاء المبدعين','C'],
            'hosting'=>['/api/admin/hosting.php','الاستضافة','◎'],
            'operations'=>['/api/admin/operations.php','مركز التشغيل','↻'],
            'runtime_validation'=>['/api/admin/runtime-validation.php','Runtime Validation','V'],
            'technology'=>['/api/admin/technology.php','المسار التقني التفاعلي','ϟ'],
        ],
        'فريق الوكلاء'=>[
            'agents'=>['/api/admin/team.php','الفريق','◉'],
            'ramy_profile'=>['/api/admin/agent-profile.php?slug=ramy','رامي — المدير','ر'],
            'walid_profile'=>['/api/admin/agent-profile.php?slug=walid','وليد — البحث','و'],
            'ayman_profile'=>['/api/admin/agent-profile.php?slug=ayman','أيمن — التنفيذ','أ'],
            'emad_profile'=>['/api/admin/agent-profile.php?slug=emad','عماد — الجودة','ع'],
            'samir_social_profile'=>['/api/admin/agent-profile.php?slug=samir-social','سمير سوشيال','س'],
            'video_director_profile'=>['/api/admin/agent-profile.php?slug=video-director','منى — الفيديو','م'],
            'basant_profile'=>['/api/admin/agent-profile.php?slug=basant','بسنت — وكالة الفارس','ب'],
            'community_manager_profile'=>['/api/admin/agent-profile.php?slug=community-manager','مدير المجتمع','ج'],
            'agent_builder'=>['/api/admin/agent-builder.php','منشئ الوكلاء','+'],
            'initiatives'=>['/api/admin/initiatives.php','مبادرات الوكلاء','I'],
            'workflow_builder'=>['/api/admin/workflow-builder.php','منشئ سير العمل','W'],
            'resources'=>['/api/admin/resources.php','الموارد والسجل','R'],
            'agent_create'=>['/api/admin/team.php#create-agent','إضافة وكيل','＋'],
            'memory'=>['/api/admin/memory.php','الذاكرة','◇'],
            'agent_learning'=>['/api/admin/agent-learning.php','تعلم الوكلاء','✦'],
            'intelligence'=>['/api/admin/intelligence.php','الأهداف والعقل والمؤشرات','G'],
        ],
        'التواصل'=>[
            'communications'=>['/api/admin/communications.php','واتساب والمحادثات','✉'],
            'social_center'=>['/api/admin/social.php','مركز السوشيال والفيديو','◉'],
            'video_studio'=>['/api/admin/video-studio.php','استوديو الفيديو','M'],
            'social_playbooks'=>['/api/admin/social-playbooks.php','خطط التشغيل الاجتماعي','S'],
            'voice_center'=>['/api/admin/voice.php','مركز الصوت والمكالمات','V'],
            'calls'=>['/api/admin/calls.php','المكالمات','☎'],
            'accounts'=>['/api/admin/accounts.php','الحسابات','▤'],
            'contacts'=>['/api/admin/contacts.php','جهات الاتصال','♧'],
        ],
        'النظام'=>[
            'provider_center'=>['/api/admin/provider-center.php','مركز قدرات المزودين','P'],
            'free_model_scout'=>['/api/admin/free-model-scout.php','نور — الموديلات المجانية','N'],
            'data_sources'=>['/api/admin/data-sources.php','مصادر البيانات المعتمدة','D'],
            'migration'=>['/api/admin/migration.php','فحص قاعدة البيانات قبل الترقية','M'],
            'integrations'=>['/api/admin/integrations.php','الذكاء الاصطناعي والربط والمفاتيح','⌘'],
            'domains'=>['/api/admin/domains.php','الدومينات','◌'],
            'health'=>['/api/admin/health.php','حالة النظام','♥'],
            'integration_health'=>['/api/admin/integration-health.php','صحة التكاملات','H'],
            'post_install_acceptance'=>['/api/admin/post-install-acceptance.php','فحص ما بعد التركيب','A'],
            'deployment_status'=>['/api/admin/deployment-status.php','بصمة النشر والاستعادة','F'],
            'doctor'=>['/api/admin/doctor.php','فحص وإصلاح النظام','✚'],
            'audit'=>['/api/admin/audit.php','سجل العمليات','≡'],
            'settings'=>['/api/admin/settings.php','الإعدادات والسياسات','⚙'],
        ],
    ];

    private static array $labels=[
        // حالات عامة
        'active'=>'شغال','idle'=>'جاهز','working'=>'شغال دلوقتي','disabled'=>'موقوف','error'=>'فيه مشكلة',
        'completed'=>'خلص','approved'=>'معتمد','approved_warning'=>'معتمد مع ملاحظات','failed'=>'فشل','blocked'=>'متوقف بسبب مشكلة',
        'needs_review'=>'مستني مراجعة','review_failed'=>'المراجعة فشلت','needs_fix'=>'محتاج إصلاح','retesting'=>'إعادة اختبار',
        'queued'=>'في الطابور','assigned'=>'اتكلف','waiting'=>'مستني','maintenance'=>'وضع صيانة','development'=>'تحت التطوير',
        'archived'=>'مؤرشف','unknown'=>'غير معروف','online'=>'متصل','pending'=>'معلّق','verified'=>'متأكد منه','untested'=>'لسه متجربش',
        'rejected'=>'مرفوض','needs_fixes'=>'محتاج إصلاحات','open'=>'مفتوح','closed'=>'مقفول','sent'=>'اتبعت','accepted'=>'مقبول',
        'draft'=>'مسودة','owner_review'=>'مراجعة المالك','confirmed'=>'مؤكد','ringing'=>'بيرن','connected'=>'متصل','unsupported'=>'غير مدعوم',
        'attempted'=>'اتحاول','executed'=>'اتنفذ','rolled_back'=>'اترجع','missing'=>'مش موجود','available'=>'متاح','removed'=>'اتحذف',
        'lead'=>'عميل محتمل','negotiating'=>'تفاوض','small'=>'تعديل بسيط','large'=>'تعديل كبير','cancelled'=>'ملغي','expired'=>'منتهي',
        'done'=>'خلص','running'=>'شغال','received'=>'اتستلم','processed'=>'اتعالج','ignored'=>'اتتجاهل','refunded'=>'اترد المبلغ',
        'requested'=>'مطلوب','created'=>'اتعمل','restored'=>'اترجع','generating'=>'بيتجهز','requires_review'=>'محتاج مراجعة',
        'started'=>'بدأ','resolved'=>'اتحل','skipped'=>'اتعدى','warning'=>'تحذير','passed'=>'نجح','normal'=>'عادي','destructive'=>'حساس',
        'not_required'=>'مش مطلوب','unconfigured'=>'مش متظبط','info'=>'معلومة','success'=>'تمام','critical'=>'حرج','medium'=>'متوسط',
        'high'=>'عالي','low'=>'منخفض','new'=>'جديد','qualified'=>'مؤهل','review'=>'يحتاج مراجعة','partial'=>'اكتمل بتحذيرات','present'=>'موجود على الاستضافة','unavailable'=>'غير متاح حاليًا','login_required'=>'يتطلب تسجيل دخول','api_required'=>'يتطلب API','public'=>'عام','easy'=>'سهل','hard'=>'صعب','read'=>'قراءة','work'=>'شغل','manage'=>'إدارة','inbound'=>'وارد','outbound'=>'صادر',
        // أولوية
        'normal_priority'=>'عادية','high_priority'=>'عالية','critical_priority'=>'حرجة','low_priority'=>'منخفضة',
        // أنواع وذاكرة
        'core'=>'الأساس','experience'=>'خبرة سابقة','project'=>'خبرة مشروع','owner_preference'=>'تفضيلات أبانوب','relationship'=>'علاقات الوكلاء','procedure'=>'إجراء متعلم',
        'image'=>'صورة','video'=>'فيديو','ui_asset'=>'عنصر واجهة','file'=>'ملف','directory'=>'مجلد','symlink'=>'اختصار ملف',
        'files'=>'ملفات','database'=>'قاعدة بيانات','full'=>'نسخة كاملة','restore_point'=>'نقطة رجوع','api'=>'واجهات الربط','secrets'=>'الأسرار','hosting'=>'الاستضافة','domains'=>'الدومينات','agents'=>'الوكلاء','tasks'=>'المهام','memory'=>'الذاكرة','whatsapp'=>'واتساب','backup'=>'النسخ الاحتياطي','deploy'=>'النشر','logs'=>'السجلات','testing'=>'الاختبارات','reports'=>'التقارير','projects'=>'المشاريع',
        'deposit'=>'عربون','preview'=>'دفعة المعاينة','final'=>'الدفعة الأخيرة','other'=>'أخرى',
        'dashboard'=>'لوحة التحكم','whatsapp'=>'واتساب','sms'=>'رسائل نصية','voice'=>'مكالمة صوتية','email'=>'بريد إلكتروني','platform'=>'رسائل المنصة','social'=>'تواصل اجتماعي','generic'=>'ربط خارجي',
        'through_ramy'=>'عن طريق رامي بس','dashboard_only'=>'من لوحة التحكم مباشرة','dashboard_whatsapp'=>'اللوحة + واتساب',
        'emergency_only'=>'للطوارئ فقط','agent_pair'=>'بين وكيلين','customer'=>'عميل','owner'=>'أبانوب','agent'=>'وكيل','system'=>'النظام','anonymous'=>'زائر غير مسجل',
        'discovered'=>'مكتشف','owner_review'=>'مراجعة المالك','approved'=>'معتمد للمتابعة','accepted'=>'مقبول','agreed'=>'تم الاتفاق','deposit_pending'=>'ينتظر العربون','awaiting_execution'=>'ينتظر التنفيذ','waiting_execution'=>'ينتظر التنفيذ','in_development'=>'قيد التطوير','in_progress'=>'قيد التنفيذ','awaiting_qa'=>'ينتظر فحص الجودة','waiting_review'=>'ينتظر المراجعة','qa'=>'قيد فحص الجودة','in_review'=>'قيد المراجعة','ready_delivery'=>'جاهز للتسليم','delivered'=>'تم التسليم','manual'=>'إضافة يدوية','system_source'=>'النظام','hosting_scan'=>'اكتشاف من الاستضافة','client_project'=>'مشروع عميل',
        'owner_dashboard'=>'من لوحة أبانوب',
        'owner_preferences'=>'تفضيلات أبانوب','security'=>'الأمان','workflow'=>'طريقة الشغل','business'=>'بيانات الشغل','communication'=>'التواصل',
        'owner_spec'=>'تعليمات أبانوب','legacy_migrated'=>'من النظام القديم','seed'=>'إعداد أساسي','learning'=>'تعلم تلقائي','fix_result'=>'نتيجة إصلاح',
        // أسماء الأدوات كما تظهر للمالك
        'task_orchestrator'=>'تنظيم وتوزيع المهام','hostinger_discovery'=>'فهرسة Hostinger (أداة قديمة)','hostinger_files'=>'ملفات Hostinger',
        'notifications'=>'الإشعارات','memory'=>'الذاكرة','task_context'=>'سياق المهمة','data_studio'=>'Data Studio للوكيل','project_files'=>'ملفات المشروع','ai_code'=>'مساعد البرمجة بالذكاء الاصطناعي',
        'backup'=>'النسخ الاحتياطي','deploy'=>'النشر','media_generation'=>'إنشاء الصور والفيديو','http_test'=>'اختبار صفحات الموقع',
        'api_test'=>'اختبار واجهات الربط','logs'=>'سجلات التشغيل','database_read'=>'قراءة قواعد البيانات','reporting'=>'إنشاء التقارير',
        'hostinger'=>'Hostinger','opportunity_hunter'=>'صيد الفرص','web_search'=>'بحث الويب','project_chat'=>'شات المشروع','master_brief'=>'Master Brief','meta_whatsapp'=>'واتساب عبر Meta','social_media'=>'إدارة السوشيال','video_production'=>'إنتاج الفيديو السينمائي','youtube'=>'YouTube','tiktok'=>'TikTok','telegram'=>'Telegram',
        // أنواع تشغيل
        'walid_scan'=>'فهرسة استضافة قديمة','walid_opportunity_search'=>'بحث وليد عن فرص','hosting_inventory'=>'فهرسة Hostinger','ayman_execute'=>'تنفيذ أيمن','emad_review'=>'مراجعة عماد','ramy_followup'=>'متابعة رامي','agent_task'=>'مهمة وكيل',
        // مزودين وقنوات داخلية
        'internal'=>'داخلي','meta'=>'ميتا','meta_whatsapp'=>'واتساب عبر ميتا','twilio'=>'Twilio','generic_bridge'=>'ربط رسائل خارجي',
        'generic_call_bridge'=>'ربط مكالمات خارجي','media_bridge'=>'ربط صور وفيديو','voice_bridge'=>'ربط مكالمات خارجي','generic_message'=>'رسائل عبر ربط خارجي',
        // أنواع فنية تظهر للمستخدم
        'ai'=>'ذكاء اصطناعي','hosting'=>'استضافة','messaging'=>'رسائل','http'=>'فحص صفحة','tls'=>'شهادة الأمان','link'=>'رابط',
        'security_header'=>'حماية المتصفح','deployment'=>'نشر','syntax'=>'سلامة الكود','database_schema'=>'هيكل قاعدة البيانات','coverage'=>'تغطية الاختبار',
        'tasks'=>'المهام','projects'=>'المشاريع','agents'=>'الوكلاء','customers'=>'العملاء','communications'=>'التواصل','domains'=>'الدومينات','database'=>'قاعدة البيانات','qa'=>'المراجعة','delivery'=>'التسليم','deployments'=>'النشر','system'=>'النظام','voice'=>'المكالمات','webhooks'=>'الاستقبالات الخارجية',
        'created'=>'اتعملت المهمة','started'=>'بدأ التنفيذ','completed'=>'خلص التنفيذ','failed'=>'فشل التنفيذ','reassigned'=>'اتنقلت المهمة','retried'=>'اتعملت إعادة تشغيل','cancelled'=>'اتلغت المهمة','rollback'=>'رجوع للنسخة','dependency_failed'=>'مهمة مرتبطة فشلت','retry_scheduled'=>'هتتعاد المحاولة',
        'auth.login'=>'تسجيل دخول','auth.login_failed'=>'محاولة دخول فاشلة','auth.login_rate_limited'=>'دخول اتوقف مؤقتًا','auth.password_changed'=>'تغيير كلمة السر','auth.owner_denied'=>'محاولة دخول غير مسموحة',
        'ramy.execute'=>'تنفيذ رامي','message.inbound'=>'رسالة واردة','agent.message'=>'رسالة بين الوكلاء','agent.owner_message'=>'رسالة للمالك','agent.create'=>'إنشاء وكيل','agent.communication'=>'تغيير تواصل وكيل','agent.project_access'=>'تغيير وصول مشروع','agent.channel_policy'=>'تغيير سياسة قناة','task.reassign'=>'نقل مهمة','task.retry'=>'إعادة مهمة','task.cancel'=>'إلغاء مهمة','task.send_emad'=>'إرسال لعماد','task.rollback'=>'رجوع تغييرات مهمة','project.rescan_requested'=>'طلب إعادة فحص','project.database_backup'=>'نسخ قواعد بيانات المشروع','database.backup'=>'نسخة قاعدة بيانات','database.statement'=>'تعديل قاعدة بيانات','database.delete'=>'حذف قاعدة بيانات','file.write'=>'تعديل ملف','file.create'=>'إنشاء ملف','file.restore'=>'استرجاع ملف','dns.update'=>'تحديث DNS','subdomain.create'=>'إنشاء دومين فرعي','subdomain.delete'=>'حذف دومين فرعي','cron.ensure'=>'تثبيت التشغيل التلقائي','website.maintenance'=>'تغيير وضع صيانة الموقع','website.delete'=>'حذف موقع','connection.test'=>'اختبار اتصال','settings.general_saved'=>'حفظ إعدادات عامة',
        'task'=>'مهمة','project_database'=>'قاعدة مشروع','domain'=>'دومين','message'=>'رسالة','conversation_session'=>'محادثة','provider'=>'مزود','settings'=>'إعدادات','file'=>'ملف','job'=>'عملية تشغيل','review'=>'مراجعة','quote'=>'عرض سعر','payment'=>'دفعة','change_request'=>'طلب تعديل','payment_method'=>'طريقة دفع',
        'delivery_status'=>'حالة التوصيل','send_failed'=>'فشل الإرسال','initiated'=>'بدأ الاتصال','answered'=>'تم الرد','completed_call'=>'انتهت المكالمة','no-answer'=>'محدش رد','busy'=>'الخط مشغول','whatsapp_call'=>'مكالمة واتساب',
        // قيم إضافية تظهر في الجداول والسجلات
        'admin'=>'إدارة','audio'=>'صوت','billing'=>'فواتير','broken'=>'فيه عطل','call_event'=>'حدث مكالمة','code'=>'كود','contact'=>'تواصل','document'=>'مستند','external'=>'خارجي','generated'=>'تم إنشاؤه','interactive'=>'تفاعلي','text'=>'نص','uploaded'=>'مرفوع','ready_with_warnings'=>'جاهز مع تنبيهات','ready_without_staging_db'=>'جاهز بدون قاعدة Staging','file_sync_failed'=>'فشل مزامنة الملفات','needs_runtime_config_or_dns'=>'يحتاج Runtime/DNS','blocked_owner'=>'متوقف لقرار المالك','completed_warning'=>'مكتمل مع تنبيه',
        'WordPress/PHP'=>'ووردبريس / PHP','Node/JavaScript'=>'نود / جافاسكربت','PHP/Composer'=>'PHP / كومبوزر','Laravel/PHP'=>'لارافيل / PHP','Static HTML'=>'موقع HTML ثابت','Unknown'=>'غير معروف',
        'admin.action'=>'عملية من لوحة الإدارة','agent.permission'=>'تغيير صلاحية وكيل','agent.status'=>'تغيير حالة وكيل','agent.tool_add'=>'إضافة أداة لوكيل','agent.tool_toggle'=>'تشغيل أو إيقاف أداة','backup.owner_verified'=>'تسجيل نقطة رجوع مؤكدة','call.owner_start'=>'بدء مكالمة مع أبانوب','change_request.approved'=>'اعتماد طلب تعديل','change_request.rejected'=>'رفض طلب تعديل','customer.message'=>'رسالة عميل','customer.project_delivered'=>'تسليم مشروع لعميل','customer.save'=>'حفظ بيانات عميل','job.retry'=>'إعادة تشغيل عملية','media.generated'=>'إنشاء صورة أو فيديو','memory.agent_add'=>'إضافة ذاكرة لوكيل','memory.company_add'=>'إضافة ذاكرة للشركة','memory.owner_preference'=>'حفظ تفضيل لأبانوب','memory.project_add'=>'إضافة ذاكرة لمشروع','project.removed_from_hosting'=>'مشروع اختفى من الاستضافة','project.status'=>'تغيير حالة مشروع','project_database.credentials'=>'حفظ بيانات قاعدة مشروع','project_database.test'=>'اختبار قاعدة مشروع','projects.scan'=>'فحص المشاريع','quote.created'=>'إنشاء عرض سعر','agent.manager'=>'تغيير المدير المباشر','payment_method.created'=>'إضافة طريقة دفع','payment_method.updated'=>'تعديل طريقة دفع',
        'admin_action'=>'عملية إدارة','call'=>'مكالمة','company_memory'=>'ذاكرة الشركة','project_asset'=>'ملف أو أصل للمشروع','project_scan'=>'فحص مشروع','user'=>'حساب مستخدم',
        'ramy'=>'رامي','walid'=>'وليد','ayman'=>'أيمن','emad'=>'عماد','samir-social'=>'سمير سوشيال','video-director'=>'منى','community-manager'=>'مدير المجتمع','basant'=>'بسنت','team'=>'الفريق','command'=>'أمر','reply'=>'رد','status'=>'حالة','result'=>'نتيجة','note'=>'ملاحظة',
    ];

    public static function header(string $title,string $active='dashboard'):void{
        $u=Auth::requireOwner();ReleaseInfo::activate();$count=Notifications::count();$items=Notifications::unread(10);$csrf=Auth::csrf();
        $critical=(int)db()->query("SELECT COUNT(*) FROM notifications WHERE read_at IS NULL AND severity='critical'")->fetchColumn();
        $failedJobs=(int)db()->query("SELECT COUNT(*) FROM jobs WHERE state='failed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();
        $systemOk=($critical+$failedJobs)===0;
        $savedTz=trim((string)setting('owner.display_timezone',config('app.timezone','Africa/Cairo')));$tzJs=json_encode($savedTz,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$csrfJs=json_encode($csrf,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="color-scheme" content="dark"><meta name="theme-color" content="#07100c"><script>(function(){try{var z=(Intl.DateTimeFormat().resolvedOptions().timeZone||"").trim();var saved='.$tzJs.';var csrf='.$csrfJs.';var m=document.cookie.match(/(?:^|;\s*)pb_tz=([^;]+)/);var c=m?decodeURIComponent(m[1]):"";if(z&&z!==saved){try{fetch("/api/admin/action.php",{method:"POST",credentials:"same-origin",keepalive:true,headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:"csrf="+encodeURIComponent(csrf)+"&action=timezone_sync&timezone="+encodeURIComponent(z)+"&return=%2Fapi%2Fadmin%2F"}).catch(function(){});}catch(x){}}if(z&&z!==c){document.cookie="pb_tz="+encodeURIComponent(z)+";path=/;max-age=31536000;SameSite=Lax";if(!sessionStorage.getItem("pb_tz_reload")){sessionStorage.setItem("pb_tz_reload","1");location.reload();return;}}else{sessionStorage.removeItem("pb_tz_reload");}}catch(e){}})();</script><title>'.e($title).' | المتر</title><link rel="stylesheet" href="/api/admin/assets/app.css?v=723"></head><body><div class="shell"><aside class="sidebar"><a class="brand" href="/api/admin/"><span class="brand-mark">م</span><span><b>المتر</b><small>إدارة الوكلاء والمشاريع</small></span></a><nav class="main-nav" aria-label="القائمة الرئيسية">';
        foreach(self::$navGroups as $group=>$itemsNav){echo '<div class="nav-group"><span class="nav-label">'.e($group).'</span>';foreach($itemsNav as $k=>$n)echo '<a class="'.($active===$k?'active':'').'" href="'.$n[0].'"><i aria-hidden="true">'.$n[2].'</i><span>'.$n[1].'</span></a>';echo '</div>';}
        echo '</nav><div class="sidebar-foot"><span class="dot '.($systemOk?'ok':'warn').'"></span> حالة التشغيل <b>'.($systemOk?'تمام':'محتاجة متابعة').'</b><small>'.($systemOk?'رامي هو قناة التواصل الأساسية':('تنبيهات أو عمليات فاشلة: '.($critical+$failedJobs))).'</small></div></aside><button class="sidebar-backdrop" type="button" data-sidebar-backdrop aria-label="إغلاق القائمة"></button><main><header class="topbar"><div class="topbar-menu-title"><button class="menu" type="button" data-menu aria-label="فتح القائمة" aria-expanded="false">☰</button><h1>'.e($title).'</h1></div><form class="global-search" method="get" action="/api/admin/search.php"><span>⌕</span><input type="search" name="q" placeholder="ابحث في الفرص والمشاريع..." aria-label="بحث شامل"></form><div class="top-actions"><a class="owner-avatar" href="/api/admin/settings.php" aria-label="حساب المالك" title="'.e($u['full_name']).'">'.e(pb_substr((string)$u['full_name'],0,2)).'</a><details class="bell" data-notification-bell data-csrf="'.e($csrf).'"><summary aria-label="الإشعارات">🔔'.($count?'<span>'.$count.'</span>':'').'</summary><div class="bell-pop"><div class="bell-head"><b>الإشعارات</b><div class="actions"><a class="link" href="/api/admin/notifications.php">عرض الكل</a></div></div>';
        if(!$items)echo '<p class="muted pad">مفيش إشعارات جديدة.</p>';
        foreach($items as $x){echo '<form class="notice '.e($x['severity']).'" data-notification-id="'.(int)$x['id'].'" method="post" action="/api/admin/action.php"><input type="hidden" name="csrf" value="'.e($csrf).'"><input type="hidden" name="action" value="notification_read"><input type="hidden" name="id" value="'.(int)$x['id'].'"><button><b>'.e($x['title']).'</b><small>'.e($x['body_text']).'</small><time>'.e(self::date($x['created_at'])).'</time></button></form>';}
        echo '</div></details><a class="message-shortcut" href="/api/admin/team-chat.php" aria-label="شات الفريق" title="شات الفريق">✉</a><span class="owner-name">'.e($u['full_name']).'</span><a class="logout" href="/api/admin/logout.php">خروج</a></div></header><div class="content">';
    }
    public static function footer():void{echo '</div></main></div><script src="/api/admin/assets/app.js?v=723"></script></body></html>';}
    public static function card(string $title,string $body,string $class=''):string{return '<section class="card '.e($class).'"><div class="card-title"><h2>'.e($title).'</h2></div>'.$body.'</section>';}
    public static function label(?string $value):string{
        $v=(string)$value;if($v==='')return '—';
        if(isset(self::$labels[$v]))return self::$labels[$v];
        $priority=self::$labels[$v.'_priority']??null;if($priority!==null)return $priority;
        return $v;
    }
    public static function badge(string $status):string{return '<span class="badge s-'.e(str_replace('_','-',$status)).'">'.e(self::label($status)).'</span>';}
    public static function priority(string $priority):string{return self::$labels[$priority.'_priority']??self::label($priority);}
    public static function channel(string $channel):string{return self::label($channel);}
    public static function provider(string $provider):string{
        return match($provider){'openrouter'=>'OpenRouter Free Router','openai'=>'OpenAI','gemini'=>'Google Gemini','anthropic'=>'Anthropic Claude','groq'=>'Groq','ollama'=>'Ollama / OpenAI Compatible','hostinger'=>'Hostinger','meta','meta_whatsapp'=>'Meta / WhatsApp','twilio'=>'Twilio','web_search'=>'بحث وليد العام','auto'=>'Auto Search','openai_web'=>'OpenAI Web Search','openrouter_web'=>'OpenRouter Web Search','duckduckgo'=>'DuckDuckGo','bing_rss'=>'Bing RSS','serper'=>'Serper / Google','brave'=>'Brave Search','tavily'=>'Tavily','internal'=>'داخلي','generic_bridge'=>'ربط رسائل خارجي','generic_call_bridge'=>'ربط مكالمات خارجي','media_bridge'=>'ربط صور وفيديو','email'=>'Email Connector','cloud_s3'=>'S3 Compatible','google_drive'=>'Google Drive','dropbox'=>'Dropbox','browser_qa'=>'Cloud Browser QA','browser_automation'=>'Browser Automation Bridge','youtube'=>'YouTube Data API','tiktok'=>'TikTok Content Posting API','telegram'=>'Telegram Bot API','meta_social'=>'Meta Social / Facebook + Instagram','facebook'=>'Facebook','instagram'=>'Instagram',default=>self::label($provider)};
    }
    public static function currency(string $currency):string{
        return match(strtoupper(trim($currency))){'EGP'=>'جنيه مصري','USD'=>'دولار','EUR'=>'يورو','SAR'=>'ريال سعودي','AED'=>'درهم إماراتي','GBP'=>'جنيه إسترليني',default=>$currency!==''?$currency:'—'};
    }
    public static function sender(array $m):string{
        $type=(string)($m['sender_type']??'');
        if($type==='owner')return 'أبانوب';
        if($type==='agent')return (string)($m['agent_name']??'')?:match((string)($m['sender_ref']??'')){'ramy'=>'رامي','walid'=>'وليد','ayman'=>'أيمن','emad'=>'عماد','samir-social'=>'سمير سوشيال','video-director'=>'منى','community-manager'=>'مدير المجتمع','basant'=>'بسنت',default=>'الوكيل'};
        if($type==='customer')return 'العميل';
        if($type==='system')return 'النظام';
        return self::label($type);
    }
    public static function messageBubble(array $m):string{
        $type=(string)($m['sender_type']??'');$sender=self::sender($m);$owner=$type==='owner';
        if($type==='customer'){$name=trim((string)($m['customer_name']??''));$phone=ConversationService::normalizePhone((string)($m['customer_phone']??''));$sender=$name!==''?$name:'العميل';if($phone!=='')$sender.=' · +'.$phone;}
        $cls=$owner?'owner':($type==='customer'?'customer':($type==='system'?'system':'agent'));
        $meta=self::channel((string)($m['channel_key']??'dashboard')).' · '.self::date($m['created_at']??null);
        if(!empty($m['status'])){$st=(string)$m['status'];$meta.=' · '.match($st){'accepted'=>'Meta قبلها للإرسال','sent'=>'أرسلتها Meta','delivered'=>'وصلت للعميل','read'=>'قرأها العميل','failed'=>'فشل الإرسال',default=>self::label($st)};}
        return '<div class="msg '.e($cls).'" data-sender="'.e($m['sender_type']??'').'" data-message-id="'.(int)($m['id']??0).'"><div class="msg-head"><b>'.e($sender).'</b><span>'.e($meta).'</span></div><div class="msg-body">'.nl2br(e((string)($m['body_text']??''))).'</div></div>';
    }
    private static function displayTimezone(): DateTimeZone {
        $name=trim((string)($_COOKIE['pb_tz']??''));
        if($name==='')$name=trim((string)setting('owner.display_timezone',config('app.timezone','Africa/Cairo')));
        try{return new DateTimeZone($name!==''?$name:'Africa/Cairo');}catch(Throwable){return new DateTimeZone('Africa/Cairo');}
    }
    private static function displayDateTime($d): ?DateTimeImmutable {
        if(!$d)return null;
        try{
            if($d instanceof DateTimeInterface){$dt=new DateTimeImmutable($d->format(DateTimeInterface::ATOM));}
            else{$raw=trim((string)$d);if($raw==='')return null;$hasZone=(bool)preg_match('/(?:Z|[+\-][0-9]{2}:[0-9]{2})$/i',$raw);$dt=new DateTimeImmutable($raw,$hasZone?null:new DateTimeZone('UTC'));}
            return $dt->setTimezone(self::displayTimezone());
        }catch(Throwable){return null;}
    }
    public static function date($d):string{$dt=self::displayDateTime($d);if(!$dt)return '—';$ampm=$dt->format('A')==='AM'?'ص':'م';return $dt->format('d/m/Y g:i').' '.$ampm;}
    public static function relative($d):string{$dt=self::displayDateTime($d);if(!$dt)return 'وقت غير محدد';$now=new DateTimeImmutable('now',self::displayTimezone());$seconds=max(0,$now->getTimestamp()-$dt->getTimestamp());if($seconds<60)return 'منذ لحظات';if($seconds<3600)return 'منذ '.max(1,(int)floor($seconds/60)).' دقيقة';if($seconds<86400)return 'منذ '.max(1,(int)floor($seconds/3600)).' ساعة';if($seconds<2592000)return 'منذ '.max(1,(int)floor($seconds/86400)).' يوم';if($seconds<31536000)return 'منذ '.max(1,(int)floor($seconds/2592000)).' شهر';return 'منذ '.max(1,(int)floor($seconds/31536000)).' سنة';}
    private static function countryFlag(string $country):string{$c=pb_strtolower(trim($country));if($c==='')return '🌐';if(str_contains($c,'مصر')||str_contains($c,'egypt'))return '🇪🇬';if(str_contains($c,'السعود')||str_contains($c,'saudi'))return '🇸🇦';if(str_contains($c,'الإمارات')||str_contains($c,'الامارات')||str_contains($c,'uae')||str_contains($c,'emirates'))return '🇦🇪';if(str_contains($c,'الكويت')||str_contains($c,'kuwait'))return '🇰🇼';if(str_contains($c,'قطر')||str_contains($c,'qatar'))return '🇶🇦';if(str_contains($c,'بريطانيا')||str_contains($c,'united kingdom')||str_contains($c,'uk'))return '🇬🇧';if(str_contains($c,'أمريكا')||str_contains($c,'امريكا')||str_contains($c,'united states')||str_contains($c,'usa'))return '🇺🇸';return '🌐';}
    private static function oppMoney($value,string $currency):string{if($value===null||$value===''||(float)$value<=0)return '—';$c=OpportunityMoney::normalizeCurrency($currency);$suffix=match($c){'EGP'=>'ج.م','USD'=>'$','EUR'=>'€','GBP'=>'£','SAR'=>'ر.س','AED'=>'د.إ','KWD'=>'د.ك','QAR'=>'ر.ق',default=>$c==='UNK'?'':$c};return $suffix==='$'||$suffix==='€'||$suffix==='£'?$suffix.' '.number_format((float)$value,0):number_format((float)$value,0).($suffix!==''?' '.$suffix:'');}
    public static function opportunityCard(array $o,int $displayRank=0,string $returnPath='/api/admin/orders.php',bool $compact=false):string{
        $id=(int)($o['id']??0);$score=max(0,min(100,(int)($o['score']??0)));$currency=(string)($o['currency']??'UNK');$pricingCurrency=$currency;$internalPricing=false;$costSource=(string)($o['cost_estimate_source']??'');if(OpportunityMoney::normalizeCurrency($currency)==='UNK'){$internalPricing=true;if(preg_match('/internal_([A-Z]{3})_/i',$costSource,$m))$pricingCurrency=strtoupper($m[1]);else $pricingCurrency=OpportunityCostEstimator::internalCurrency();}$budgetMin=(float)($o['budget_min']??0);$budgetMax=(float)($o['budget_max']??0);$cost=(float)($o['estimated_cost']??0);$profit=(float)($o['projected_profit']??0);$offer=(float)($o['suggested_offer']??0);$country=trim((string)($o['country']??''));$source=trim((string)($o['source']??''))?:'مصدر غير محدد';$title=trim((string)($o['title_ar']??''))?:trim((string)($o['title']??''))?:'فرصة بدون عنوان';$when=$o['last_verified_at']??$o['discovered_at']??$o['created_at']??null;$tags=json_decode((string)($o['tags_json']??''),true);if(!is_array($tags))$tags=[];$status=(string)($o['status']??'new');$risk=trim((string)($o['risk_level']??''));$type=trim((string)($o['opportunity_type']??$o['category']??''));$difficulty=(string)($o['difficulty']??'unknown');$summary=trim((string)($o['summary_ar']??''))?:trim((string)($o['requirements_text']??''));$rank=$displayRank>0?$displayRank:((int)($o['shortlist_rank']??0));
        $budget='غير معلنة';if($budgetMin>0||$budgetMax>0){$a=$budgetMin>0?self::oppMoney($budgetMin,$currency):'';$b=$budgetMax>0?self::oppMoney($budgetMax,$currency):'';$budget=$a!==''&&$b!==''&&$budgetMin!==$budgetMax?$a.' — '.$b:($b!==''?$b:$a);}
        $chips=[];if($type!=='')$chips[]=$type;$chips[]=$difficulty==='easy'?'تنفيذ سريع':($difficulty==='hard'?'تنفيذ معقد':'تنفيذ متوسط');if($internalPricing)$chips[]='تسعير داخلي '.OpportunityMoney::normalizeCurrency($pricingCurrency);if($risk!=='')$chips[]='المخاطر: '.self::label($risk);foreach(array_slice($tags,0,$compact?2:4) as $t){$t=trim((string)$t);if($t!=='')$chips[]=$t;}
        $out='<article class="opportunity-card-v48'.($compact?' is-compact':'').($status==='rejected'?' is-rejected':'').'"><aside class="opportunity-score-panel"><div class="opportunity-score-number">'.$score.'<small>/100</small></div><span>'.e($score>=85?'فرصة ممتازة':($score>=70?'فرصة جيدة':'تحتاج مراجعة')).'</span>'.($rank>0?'<b>#'.$rank.'</b>':'').'</aside><div class="opportunity-card-main"><div class="opportunity-meta-v48"><span>'.self::countryFlag($country).' '.e($country?:'غير محدد').'</span><i>•</i><span>'.e($source).'</span><i>•</i><span>◷ '.e(self::relative($when)).'</span></div><h2>'.e($title).'</h2><div class="opportunity-tags-v48">';foreach(array_slice(array_values(array_unique($chips)),0,$compact?4:6) as $chip)$out.='<span>'.e($chip).'</span>';$out.='</div>';
        if(!$compact&&$summary!=='')$out.='<p class="opportunity-summary-v48">'.e(pb_substr($summary,0,240)).'</p>';
        $out.='<div class="opportunity-finance-v48"><div><small>ميزانية العميل</small><strong>'.e($budget).'</strong></div><div><small>عرضنا المقترح'.($internalPricing?' (تقديري)':'').'</small><strong class="gold">'.e($offer>0?self::oppMoney($offer,$pricingCurrency):'يحتاج تسعير').'</strong></div><div><small>التكلفة علينا'.($internalPricing?' (تقديرية)':'').'</small><strong>'.e($cost>0?self::oppMoney($cost,$pricingCurrency):'—').'</strong></div><div><small>صافي الربح المتوقع</small><strong class="profit">'.e($profit>0?self::oppMoney($profit,$pricingCurrency):'—').'</strong></div></div><div class="opportunity-actions-v48">';
        if(in_array($status,['new','needs_review'],true)){$out.='<form method="post" action="/api/admin/action.php">'.self::csrf().'<input type="hidden" name="action" value="opportunity_decide"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="decision" value="approved"><input type="hidden" name="return" value="'.e($returnPath).'"><button class="btn opportunity-approve">✓ موافقة وتجهيز العرض</button></form><form method="post" action="/api/admin/action.php">'.self::csrf().'<input type="hidden" name="action" value="opportunity_decide"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="decision" value="rejected"><input type="hidden" name="return" value="'.e($returnPath).'"><button class="btn secondary opportunity-reject">✕ استبعاد</button></form>';}
        $out.='<a class="btn secondary opportunity-details" href="/api/admin/orders.php?id='.$id.'">عرض التفاصيل</a>';if(!$compact&&!empty($o['source_url']))$out.='<a class="btn secondary opportunity-source" target="_blank" rel="noopener" href="'.e((string)$o['source_url']).'">↗</a>';$out.='</div></div></article>';return $out;
    }
    public static function flash():string{Auth::start();$m=(string)($_SESSION['flash']??'');$t=(string)($_SESSION['flash_type']??'success');unset($_SESSION['flash'],$_SESSION['flash_type']);return $m?'<div class="flash '.e($t).'" role="status"><span>'.e($m).'</span><button type="button" class="flash-close" data-flash-close aria-label="إغلاق">×</button></div>':'';}
    public static function alert(string $message,string $type='info'):string{$message=trim($message);if($message==='')return '';$type=in_array($type,['success','error','warning','info'],true)?$type:'info';return '<div class="flash '.e($type).'" role="status"><span>'.e($message).'</span><button type="button" class="flash-close" data-flash-close aria-label="إغلاق">×</button></div>';}
    public static function csrf():string{return '<input type="hidden" name="csrf" value="'.e(Auth::csrf()).'">';}
    public static function tabs(array $tabs,string $active):string{$out='<div class="tabs module-tabs">';foreach($tabs as $key=>$tab){$href=is_array($tab)?$tab[1]:'#';$label=is_array($tab)?$tab[0]:(string)$tab;$out.='<a class="'.($key===$active?'active':'').'" href="'.e($href).'">'.e($label).'</a>';}$out.='</div>';return $out;}
    public static function stat(string $label,$value,string $note=''):string{return '<section class="card stat-card"><span class="muted small">'.e($label).'</span><div class="stat">'.e($value).'</div>'.($note?'<small class="muted">'.e($note).'</small>':'').'</section>';}
    public static function empty(string $text):string{return '<div class="empty">'.e($text).'</div>';}
    public static function humanError(string $code):string{
        $m=[
            'csrf_invalid'=>'الجلسة انتهت أو الصفحة قديمة. حدّث الصفحة وحاول تاني.',
            'login_rate_limited'=>'محاولات الدخول كتير. جرّب بعد شوية.',
            'project_required'=>'اختار المشروع الأول.',
            'task_required'=>'اختار المهمة الأول.',
            'message_required'=>'اكتب الرسالة الأول.',
            'quote_not_found'=>'عرض السعر مش موجود.',
            'project_not_found'=>'المشروع مش موجود.',
            'task_not_found'=>'المهمة مش موجودة.',
            'agent_not_found'=>'الوكيل مش موجود.',
            'invalid_domain'=>'اسم الدومين مش صحيح.',
            'verified_backup_required'=>'لازم يكون فيه نسخة احتياطية كاملة أو نقطة رجوع مؤكدة قبل الحذف.',
            'destructive_confirmation_required'=>'العملية دي حساسة ومحتاجة تأكيد واضح من المالك.',
            'maintenance_supported_for_wordpress_only'=>'زر الصيانة المباشر متاح حاليًا لمواقع ووردبريس المدعومة فقط.',
            'customer_message_send_failed'=>'تعذر إرسال رسالة العميل. راجع ربط WhatsApp/Meta وحالة الرقم.',
            'meta_not_configured'=>'ربط Meta/WhatsApp غير مكتمل. راجع Access Token وPhone Number ID من صفحة الربط.',
            'meta_invalid_recipient'=>'رقم واتساب غير صالح للإرسال. يجب أن يكون رقمًا دوليًا حقيقيًا بدون رموز زائدة.',
            'meta_empty_message'=>'لا يمكن إرسال رسالة واتساب فارغة.',
            'meta_send_no_message_id'=>'Meta قبلت الاتصال لكن لم تُرجع Message ID؛ اعتبرت الإرسال غير مؤكد وسجلت المشكلة.',
            'meta_template_required_outside_customer_window'=>'نافذة الـ24 ساعة للعميل مقفولة. لازم تضبط اسم قالب WhatsApp معتمد لبدء المحادثة، وبعد رد العميل يمكن إرسال الرسالة العادية.',
            'meta_template_name_invalid'=>'اسم قالب WhatsApp غير صحيح. استخدم الاسم المعتمد داخل Meta بحروف إنجليزية صغيرة وأرقام وشرطة سفلية فقط.',
            'meta_template_language_invalid'=>'كود لغة قالب WhatsApp غير صحيح. استخدم مثل ar أو en_US حسب القالب المعتمد.',
            'message_body_required'=>'اكتب نص الرسالة قبل الإرسال.',
            'customer_phone_missing'=>'العميل لا يملك رقم هاتف صالحًا للإرسال.',
            'opportunity_currency_unverified'=>'عملة الفرصة غير مؤكدة من المصدر، لذلك لن يتم إنشاء عرض تلقائي قبل مراجعتها.',
            'customer_conversation_required'=>'التحكم ده متاح لمحادثات العملاء فقط.',
            'customer_blocked'=>'العميل محظور حاليًا، لذلك لن يتم إرسال رسائل له قبل رفع الحظر.',
            'owner_whatsapp_send_failed'=>'تعذر إرسال رسالة WhatsApp للمالك. راجع اتصال Meta.',
            'voice_provider_not_configured'=>'مفيش مزود مكالمات متظبط لسه.',
            'current_password_invalid'=>'كلمة المرور الحالية مش صحيحة.',
            'password_confirmation_mismatch'=>'كلمتا المرور الجديدة مش متطابقتين.',
            'password_too_weak'=>'كلمة المرور الجديدة لازم تكون 12 حرف على الأقل وفيها حروف وأرقام.','invalid_agent_manager'=>'المدير المختار غير صحيح.','agent_manager_cycle'=>'مينفعش تعمل دائرة إدارة بين الوكلاء.','payment_method_invalid'=>'راجع بيانات طريقة الدفع؛ فيه خانة ناقصة أو طويلة زيادة.',
            'opportunity_not_found'=>'الفرصة غير موجودة.',
            'opportunity_source_invalid'=>'راجع اسم ونوع ومفتاح مصدر البحث.',
            'opportunity_url_invalid'=>'رابط المصدر غير صحيح.',
            'web_search_provider_not_configured'=>'بحث الويب غير جاهز. اختر Auto لاستخدام Serper/Brave/Tavily عند توفرها أو OpenAI Web Search من الربط الحالي، مع fallback محدود عند الحاجة.',
            'search_api_not_configured'=>'لا يوجد مزود بحث موثوق جاهز لوليد. اربط Serper أو Brave أو Tavily أو OpenAI Web Search، أو اختر OpenRouter Web Search يدويًا إذا كنت موافقًا على تكلفة البحث.',
            'search_zero_results'=>'مزود البحث اتصل فعليًا لكنه لم يرجع نتائج لهذا الاختبار. راجع تشخيص المزود أو جرّب استعلامًا أوسع.',
            'project_discovery_ok'=>'تم الوصول إلى مصدر واحد على الأقل واستخراج روابط مشاريع فردية حقيقية.',
            'project_discovery_unavailable'=>'فشل اكتشاف روابط مشاريع فردية من المصادر العامة ومحركات البحث. افتح تشخيص البحث لمعرفة المصدر المحجوب أو الذي أعاد صفر روابط.',
            'no_individual_project_links'=>'المصدر فتح، لكنه لم يعطِ روابط مشاريع فردية يمكن الاعتماد عليها.',
            'web_search_provider_unsupported'=>'مزود البحث المحدد غير مدعوم.',
            'walid_zero_results'=>'لم يجد وليد فرصًا مؤهلة في هذه الجولة. هذا ليس عطلًا تقنيًا؛ راجع ملخص البحث أو وسّع النطاق إذا لزم.',
            'walid_search_unavailable'=>'تعذر على وليد الوصول إلى أي مصدر بحث صالح في هذه الجولة. راجع مزود البحث والمفاتيح واتصال الخادم ثم أعد المحاولة.',
            'worker_lease_expired'=>'انتهت مهلة تنفيذ Worker قبل اكتمال المهمة؛ أعاد النظام المهمة للطابور تلقائيًا.',
            'stale_worker_exhausted'=>'انتهت محاولات Worker بدون اكتمال موثّق. راجع سجل المهمة وحالة Worker قبل إعادة التشغيل.',
            'upgrade_file_missing'=>'ملف ترقية قاعدة البيانات غير موجود مع ملفات التحديث.',
            'upgrade_lock_busy'=>'ترقية قاعدة البيانات تعمل بالفعل في عملية أخرى. انتظر قليلًا ثم أعد المحاولة.',
            'upgrade_statement_failed'=>'توقفت ترقية قاعدة البيانات عند أمر SQL. افتح فحص النظام لرؤية مرجع الخطأ والسبب.',
            'opportunity_budget_missing'=>'لا توجد ميزانية كافية لتجهيز سعر تلقائي؛ يحتاج رامي مراجعة الفرصة.',
            'master_brief_required'=>'لا يمكن بدء التنفيذ قبل وجود Master Brief معتمد للمشروع.',
            'learning_not_found'=>'سجل التعلم غير موجود.',
            'learning_decision_invalid'=>'قرار مراجعة التعلم غير صحيح.',
            'ai_provider_invalid'=>'مزود الذكاء المحدد غير صحيح.',
            'database_connection_lost'=>'اتصال MySQL انتهت مهلته أثناء عملية طويلة. Company OS 20.1.4 يعيد الاتصال تلقائيًا؛ أعد نفس المهمة بدل تغيير مزود الذكاء.',
            'ai_routes_exhausted'=>'فشلت كل مسارات الذكاء للمهمة. راجع OpenRouter وFallback models؛ لن أعتبر HTTP 404 هنا مشكلة Hostinger.',
            'ai_invalid_json'=>'الموديل رجع JSON غير صالح للمهمة المنظمة. سيعاد الطلب بصيغة JSON صارمة ثم يُستخدم Fallback.',
            'openrouter_empty_output'=>'OpenRouter اتصل لكن لم يرجع إجابة نهائية مرئية؛ سيعاد بدون Reasoning ثم يُستخدم Fallback.',
            'write_verification_failed'=>'تم رفع الملف لكن قراءة التحقق لم تطابق المحتوى المتوقع. النظام سيعيد القراءة بتدرج زمني ثم يحاول رفعًا واحدًا جديدًا بمفتاح رفع حديث.',
            'write_verification_failed_after_retry'=>'تعذر إثبات الكتابة حتى بعد إعادة القراءة والمحاولة الآمنة الثانية. تم إيقاف المسار بدل تسجيل نجاح غير مؤكد.',
            'task_evidence_required'=>'لا يمكن إغلاق المهمة بدون دليل تنفيذ أو اختبار متحقق منه.',
            'unknown_action'=>'العملية المطلوبة مش معروفة.',
            'permission_not_found'=>'الصلاحية المطلوبة مش موجودة.',
            'secrets_view_owner_only'=>'عرض الأسرار نفسها غير مسموح للوكلاء.',
            'business_name_invalid'=>'اسم الشركة لازم يكون واضح ومش طويل زيادة.',
            'owner_name_invalid'=>'اسم المالك لازم يكون واضح ومش طويل زيادة.',
            'owner_phone_invalid'=>'رقم المالك مش بصيغة صحيحة.',
            'owner_channel_invalid'=>'قناة التواصل الأساسية غير صحيحة.',
            'permission_denied'=>'الوكيل معندوش الصلاحية المطلوبة للعملية دي.',
            'project_access_denied'=>'الوكيل معندوش وصول كفاية للمشروع ده.',
            'meta_waba_id_missing'=>'معرّف حساب WhatsApp Business (WABA ID) غير محفوظ. النظام سيحاول استعادته من آخر Webhook صحيح؛ لو لم يجده راجع إعداد Meta.',
            'meta_template_management_permission_missing'=>'التوكن الحالي يقدر يرسل رسائل WhatsApp، لكنه لا يملك صلاحية إدارة/قراءة القوالب whatsapp_business_management. استخدم System User Token فيه whatsapp_business_messaging وwhatsapp_business_management، أو اكتب اسم قالب Approved يدويًا واختبره.',
            'meta_template_management_probe_failed'=>'إرسال WhatsApp متاح، لكن فحص قوالب العملاء فشل. راجع صلاحية whatsapp_business_management وWABA ID.',
            'meta_sample_template_not_allowed'=>'قالب hello_world/sample قالب تجريبي ولا يصلح لرقم WhatsApp الإنتاجي. استخدم قالب افتتاح مخصص Approved من حساب الشركة.',
            '131058'=>'قالب hello_world التجريبي لا يمكن إرساله من رقم الشركة الحقيقي. تم منعه؛ استخدم قالب افتتاح مخصص Approved.',
            'gemini_models_unavailable'=>'الموديل المحفوظ غير متاح للمفتاح الحالي. النظام سيقرأ قائمة موديلات Gemini المتاحة فعليًا ويختار موديل generateContent صالح.',
            'meta_access_token_invalid'=>'Access Token الخاص بـMeta غير صالح أو انتهت صلاحيته. استبدله بـSystem User Token صالح.',
            'meta_messaging_unavailable'=>'ربط رقم WhatsApp نفسه لا يعمل للإرسال. راجع التوكن وPhone Number ID.',
            'meta_configured_template_not_approved'=>'اسم القالب المحفوظ غير موجود ضمن قوالب Approved بنفس اللغة. راجع الاسم واللغة من WhatsApp Manager.',
            'meta_template_unverified_send_required'=>'القالب مكتوب يدويًا لكن صلاحية قراءة القوالب غير متاحة. نفّذ اختبار قالب الافتتاح؛ نجاح التسليم يثبت القالب بدون الحاجة لصلاحية إدارة القوالب.',
            'meta_customer_outbound_not_ready'=>'واتساب يعمل للمحادثات المفتوحة، لكن بدء محادثة عميل خارج نافذة 24 ساعة غير جاهز بعد. راجع القالب وصلاحيات Meta.',
            'meta_no_approved_template'=>'لا يوجد قالب WhatsApp Approved صالح لبدء محادثة خارج نافذة 24 ساعة. أنشئ/اعتمد قالب افتتاح في Meta ثم أعد الإصلاح.',
            'meta_template_required_outside_customer_window'=>'نافذة 24 ساعة مقفولة ولا يوجد قالب افتتاح Approved جاهز. الرسالة الأصلية ستظل محفوظة حتى يتوفر القالب أو يرد العميل.',
            'hostinger_not_configured'=>'مفتاح Hostinger مش متظبط.',
            'openrouter_not_configured'=>'مفتاح OpenRouter غير متظبط أو غير محفوظ في الخزنة. افتح المفاتيح والربط وأضف المفتاح ثم اختبر الاتصال.',
            'openrouter_connection_not_verified'=>'تم حفظ إعداد OpenRouter لكن اختبار الاتصال لم ينجح، لذلك لم يتم تحويل الوكلاء إليه كـPrimary. راجع آخر اختبار اتصال ثم أعد المحاولة.',
            'openrouter_quota_exhausted'=>'مفتاح OpenRouter اتعرف عليه، لكن حصة النماذج المجانية انتهت حاليًا. المفتاح ليس مفقودًا؛ استخدم Fallback متاح أو ارفع حصة OpenRouter ثم أعد الاختبار.',
            'openrouter_key_http_401'=>'المفتاح النشط فشل في المصادقة. النظام جرّب فحص /key وInference بنفس المفتاح؛ راجع Fingerprint الظاهر في صفحة الربط، وتأكد أنك تستخدم API Key عاديًا وليس Management Key.',
            'openrouter_vault_replace_failed'=>'فشل التحقق من استبدال مفتاح OpenRouter داخل SecretVault؛ لم يتم اعتماد التغيير.',
            'openrouter_inference_http_401'=>'OpenRouter قبل فحص المفتاح لكن طلب Inference رجع 401. راجع نوع المفتاح وسياسة الحساب ثم أعد الاختبار؛ النظام يسجل الآن مرحلة الفشل بدقة.',
            'openai_models_unavailable'=>'مفتاح OpenAI وصل للخدمة لكن الموديل المحفوظ غير متاح. النظام قرأ قائمة الموديلات وحاول تلقائيًا موديلات Responses المتاحة؛ راجع صلاحيات المفتاح إذا استمر الفشل.',
            'openrouter_management_key_not_for_inference'=>'المفتاح المحفوظ هو Management API Key. هذا النوع لإدارة المفاتيح فقط ولا يعمل مع Chat Completions؛ استخدم API Key عادي من OpenRouter.',
            'openrouter_empty_output'=>'مفتاح OpenRouter صالح والاتصال وصل إلى نموذج فعلي، لكن النموذج لم يُرجع نصًا نهائيًا. النظام سيعيد المحاولة بميزانية إخراج أكبر وبدون Reasoning في اختبار الاتصال.',
            'ai_empty_output'=>'مزود الذكاء استجاب لكن بدون نص نهائي. تم اعتبار الاختبار غير مكتمل بدل الادعاء بنجاحه.',
            'worker_http_kick_failed'=>'تعذر إيقاظ Worker عبر HTTPS. شغّل «إيقاظ Worker الآن» وراجع Cron/Hostinger إذا استمرت الحالة.',
            'worker_http_kick_forbidden'=>'Worker رفض طلب الإيقاظ لأن رمز التشغيل غير متطابق. أعد تركيب Worker Cron من صفحة التشغيل.',
            'openai_not_configured'=>'مفتاح OpenAI غير متظبط أو غير محفوظ في الخزنة.',
            'openai_model_not_configured'=>'نموذج OpenAI غير محدد. النسخة الحالية تستخدم gpt-5.6 كافتراضي آمن.',
            'ai_routes_exhausted'=>'كل مزودي الذكاء المتاحين فشلوا. راجع صفحة فحص النظام وسجل الربط.',
            'gemini_models_unavailable'=>'موديل Gemini المحفوظ غير متاح لهذا المفتاح. النظام يقرأ models.list ويختار موديلًا يدعم generateContent فعليًا؛ لو استمر الخطأ راجع صلاحية المفتاح أو استخدم OpenAI كـPrimary.',
            'database_upgrade_incomplete'=>'ترقية قاعدة البيانات غير مكتملة. افتح فحص النظام ثم «إصلاح قاعدة البيانات الآن» لتطبيق آخر Migration متاحة.',
            'project_database_credentials_required'=>'بيانات اتصال قاعدة المشروع ناقصة في الخزنة المشفرة.',
            'project_database_missing'=>'مفيش قاعدة بيانات نشطة مسجلة للمشروع.',
            'multiple_databases_need_name'=>'المشروع فيه أكتر من قاعدة بيانات؛ لازم تحدد القاعدة المقصودة.',
            'database_not_found_in_project'=>'قاعدة البيانات دي مش مسجلة مع المشروع.',
            'backup_reference_required'=>'اكتب مرجع النسخة أو نقطة الرجوع.',
            'backup_not_verified'=>'النسخة الاحتياطية مش متأكد منها.',
            'curl_extension_missing'=>'امتداد الاتصال بالإنترنت cURL مش شغال على السيرفر.',
            'host_unresolved'=>'تعذر الوصول لعنوان الخدمة الخارجي.',
            'private_address_blocked'=>'تم منع عنوان داخلي غير آمن.',
            'signature_invalid'=>'توقيع الاستقبال الخارجي غير صحيح.',
            'agent_owner_contact_not_allowed'=>'التواصل المباشر مع أبانوب مش مسموح للوكيل ده.',
            'ramy_approval_required'=>'العملية دي محتاجة تمر على رامي الأول.',
            'system_project_owner_authorization_required'=>'تعديل نظام الوكلاء نفسه محتاج أمر صريح من أبانوب.',
            'project_database_context_missing'=>'المهمة محتاجة قاعدة بيانات واضحة للمشروع.',
            'dangerous_code_generated'=>'تم منع كود خطر قبل رفعه للموقع.',
            'agent_disabled'=>'الوكيل متوقف حاليًا. شغله الأول لو عايز المهمة تكمل.',
            'agent_tool_disabled'=>'أداة مطلوبة للوكيل مقفولة من صفحة الوكيل. المهمة اتوقفت بدل ما تتنفذ ناقصة.',
            'agent_recovery_required'=>'التنفيذ اتوقف لأن فيه أثر محتاج استرجاع أو مراجعة قبل إعادة المحاولة.',
            'selected_file_not_found'=>'أيمن اختار ملف للتعديل لكنه مش موجود في الفهرس الحالي، فتم إيقاف التنفيذ بدل التخمين.',
            'selected_file_too_large'=>'الملف المختار كبير على تعديل آمن في خطوة واحدة. لازم يتقسم أو يتراجع يدويًا.',
            'selected_create_file_exists'=>'أيمن حاول ينشئ ملف موجود بالفعل، فتم منع الكتابة فوقه.',
            'generated_file_empty'=>'تم منع إنشاء ملف فاضي.',
            'php_syntax_invalid'=>'تم منع رفع ملف PHP فيه خطأ في الصياغة.',
            'json_syntax_invalid'=>'تم منع رفع ملف JSON غير سليم.',
            'database_plan_partial_failure'=>'تعديل قاعدة البيانات توقف بعد تنفيذ جزء منه. النسخة الاحتياطية محفوظة ولازم مراجعة القاعدة قبل استكمال المهمة.',
            'destructive_sql_blocked'=>'تم منع أمر قاعدة بيانات تدميري.',
            'staging_not_prepared'=>'محطة الاختبار للمشروع لم تُجهز بعد.',
            'staging_not_qa_approved'=>'لا يمكن النقل النهائي قبل اعتماد عماد لنسخة Staging.',
            'final_domain_missing'=>'المشروع لا يحتوي دومين عميل نهائيًا بعد.',
            'final_domain_is_staging'=>'الدومين النهائي ما زال هو دومين الاختبار؛ حدد دومين العميل أولًا.',
            'staging_database_isolation_required'=>'المهمة تحتاج قاعدة بيانات منفصلة على Staging قبل تنفيذ تغييرات قاعدة الإنتاج.',
            'staging_wordpress_promotion_requires_managed_migration'=>'مشروع ووردبريس يحتاج ترحيلًا مُدارًا للملفات وقاعدة البيانات بدل نسخ الملفات فقط.',
            'staging_promotion_partial_failure'=>'النقل من Staging توقف بعد جزء من الملفات. راجع سجل النشر قبل إعادة المحاولة.',
            'update_requires_where'=>'تم منع تحديث قاعدة بيانات من غير شرط يحدد السجلات.',
        ];
        if(str_contains($code,'131047'))return 'WhatsApp رفض الرسالة الحرة لأن آخر رد من العميل أقدم من 24 ساعة. استخدم قالب افتتاح معتمد، ثم أكمل الرسائل العادية بعد رد العميل.';
        if(str_contains($code,'131058'))return 'النظام حاول استخدام hello_world على رقم WhatsApp إنتاجي. هذا القالب للتجربة فقط؛ تم منعه ويجب استخدام قالب افتتاح مخصص Approved.';
        if(str_contains(pb_strtolower($code),'hello world templates can only be sent from the public test numbers'))return 'قالب hello_world خاص بأرقام Meta التجريبية فقط. تم منعه من مسار العملاء على رقم الشركة.';
        if(isset($m[$code]))return $m[$code];foreach($m as $key=>$message)if(str_starts_with($code,$key.':'))return $message;return 'حصل خطأ فني غير متوقع. راجع سجل النظام أو حالة الربط.';
    }
}
