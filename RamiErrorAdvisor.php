<?php
declare(strict_types=1);

final class RamiErrorAdvisor {
    public static function explain(Throwable|string $error,string $intent=''):array {
        $raw=$error instanceof Throwable?$error->getMessage():(string)$error;
        $safe=trim(Security::redactSecrets($raw,700));
        $code=self::code($safe);
        [$title,$cause,$fix,$canRetry]=self::map($code,$safe,$intent);
        return [
            'code'=>$code,
            'title'=>$title,
            'cause'=>$cause,
            'fix'=>$fix,
            'can_retry'=>$canRetry,
            'technical'=>$safe,
        ];
    }

    public static function ownerText(Throwable|string $error,string $intent=''):string {
        $e=self::explain($error,$intent);
        $text='الخطأ: '.$e['title'].'.';
        if($e['code']!=='unknown')$text.=' ('.$e['code'].')';
        $text.="\nالسبب: ".$e['cause']."\nالإصلاح: ".$e['fix'];
        if($e['technical']!=='' && !self::sameMeaning($e['technical'],$e['cause']))$text.="\nالتفصيل التقني: ".pb_substr($e['technical'],0,260);
        return $text;
    }

    private static function code(string $s):string {
        if(preg_match('/^ramy_repair_failed:([a-z0-9_.-]+)/i',$s,$m))return strtolower($m[1]);
        if(str_starts_with(strtolower($s),'database_connection_lost:')||Database::isDisconnect($s))return 'database_connection_lost';
        // Preserve the top-level subsystem error before looking for nested HTTP codes.
        // Otherwise ai_routes_exhausted:...http_404... was incorrectly explained as a missing Hostinger path.
        if(str_starts_with(strtolower($s),'ai_routes_exhausted:'))return 'ai_routes_exhausted';
        if(str_starts_with(strtolower($s),'ai_invalid_json'))return 'ai_invalid_json';
        if(str_starts_with(strtolower($s),'openrouter_'))return strtolower(trim((string)strtok($s,':')));
        if(str_starts_with(strtolower($s),'browser_automation_')||str_starts_with(strtolower($s),'hostinger_'))return strtolower(trim((string)strtok($s,':')));
        // Meta errors are often wrapped as http_400:(#131xxx). Prefer the actionable Meta code.
        if(preg_match('/\b(1310[0-9]{2})\b/',$s,$m))return $m[1];
        if(preg_match('/\b(http_[0-9]{3})\b/i',$s,$m))return strtolower($m[1]);
        $prefix=trim((string)strtok($s,':'));
        if($prefix!=='' && preg_match('/^[a-z0-9_.-]{3,120}$/i',$prefix))return strtolower($prefix);
        return 'unknown';
    }

    private static function map(string $code,string $raw,string $intent):array {
        if($code==='131047')return ['نافذة WhatsApp مقفولة','مرّ أكثر من 24 ساعة من آخر رسالة للعميل، لذلك Meta لا تسمح بنص حر كبداية جديدة.','أرسل قالب افتتاح Approved تلقائيًا، واحفظ الرسالة الأصلية. أول ما العميل يرد افتح نافذة الخدمة وأرسل الرسالة المحفوظة ثم كمل التفاوض.',true];
        if($code==='131058')return ['قالب WhatsApp تجريبي على رقم إنتاجي','النظام حاول استخدام hello_world، وMeta تسمح بهذا القالب فقط من Public Test Numbers.','امنع hello_world نهائيًا، امسح اختياره من الإعدادات، أعد الرسائل الفاشلة للطابور، ثم اختر أو أنشئ قالب افتتاح مخصص Approved لرقم الشركة الحقيقي.',true];
        if($code==='meta_sample_template_not_allowed')return ['قالب Meta التجريبي مرفوض للإنتاج','تم اكتشاف hello_world/sample قبل الإرسال ومنعه حتى لا يتكرر خطأ 131058.','اختر قالبًا مخصصًا Approved أو اسمح للنظام بإنشاء elmetr_project_contact ثم انتظر موافقة Meta.',true];
        if($code==='131026')return ['تعذر توصيل رسالة WhatsApp','Meta لم تستطع توصيل الرسالة للرقم المستهدف.','راجع صيغة الرقم الدولية وأن الرقم عليه WhatsApp، ثم أعد المحاولة بعد التحقق.',true];
        if($code==='131049')return ['قيد جودة/تفاعل من WhatsApp','Meta أوقفت الرسالة بسبب قيود جودة أو تفاعل للحساب/القالب.','راجع جودة الحساب وحالة القالب في WhatsApp Manager قبل إعادة الإرسال.',false];
        if($code==='database_connection_lost')return ['اتصال قاعدة البيانات انقطع مؤقتًا','اتصال MySQL القديم انتهت مهلته أثناء طلب AI طويل؛ المشكلة ليست فشل OpenRouter/OpenAI/Gemini أنفسهم.','Company OS 20.1.4 يعيد إنشاء اتصال PDO تلقائيًا قبل الكتابة بعد طلبات AI الطويلة. أعد نفس المهمة؛ إذا استمر الخطأ بعد ظهور 20.1.4 في System Doctor راجع خدمة MySQL/Hostinger بدل تغيير موديلات الذكاء.',true];
        if($code==='ai_routes_exhausted'){
            $parts=[];
            if(str_contains($raw,'openrouter:ai_invalid_json'))$parts[]='OpenRouter أعاد نصًا غير صالح كـJSON في مهمة منظمة';
            if(str_contains($raw,'openai:http_404'))$parts[]='Fallback الخاص بـOpenAI حاول استخدام موديل غير تابع لـOpenAI أو غير متاح';
            if(str_contains($raw,'gemini:http_400')||str_contains($raw,'unexpected model name format'))$parts[]='Fallback الخاص بـGemini استلم اسم موديل بصيغة غير مناسبة';
            $cause=$parts?implode('، ',$parts):'كل مسارات الذكاء المتاحة للمهمة فشلت في نفس المحاولة.';
            return ['فشل مسارات الذكاء الاصطناعي',$cause,'ثبّت OpenRouter كـPrimary مع موديله الخاص، ولا تورّث موديل OpenRouter إلى OpenAI/Gemini. أعد المهمة بعد إصلاح Routes؛ الأخطاء الدائمة في الموديل لا تحتاج انتظار عدة دقائق في الطابور.',true];
        }
        if($code==='ai_invalid_json')return ['رد AI غير صالح كـJSON','الموديل رجع نصًا لا يطابق صيغة JSON المطلوبة للمهمة المنظمة.','أعد الطلب بتعليمات JSON صارمة ومحاولة إصلاح واحدة، ثم انتقل إلى Fallback بموديل خاص بالمزود.',true];
        if(str_starts_with($code,'openrouter_empty_output'))return ['OpenRouter رجع بدون إجابة نهائية','الموديل المختار لم يُخرج نصًا مرئيًا رغم نجاح الاتصال.','أعد المحاولة بميزانية إخراج أكبر وبدون Reasoning، ثم استخدم Fallback إذا استمر.',true];
        if($code==='openrouter_quota_exhausted')return ['حصة OpenRouter المجانية انتهت','فحص المفتاح نجح لكن Inference رجع Rate Limit للحصة المجانية.','استخدم Fallback متاح مؤقتًا أو ارفع حصة OpenRouter ثم أعد الاختبار.',true];
        if($code==='openrouter_key_http_401')return ['OpenRouter رفض المفتاح','فحص /key رجع 401، لذلك المشكلة في المفتاح نفسه أو صلاحيته.','أعد حفظ API Key عادي صالح ثم اختبر OpenRouter.',true];
        if($code==='openrouter_inference_http_401')return ['OpenRouter رفض طلب Inference','المفتاح اجتاز فحصه لكن طلب النموذج رجع 401.','راجع نوع المفتاح/الحساب وسياسة OpenRouter ثم أعد الاختبار؛ لا تغيّر Hostinger بسبب هذا الخطأ.',true];
        if($code==='openai_models_unavailable')return ['موديلات OpenAI غير متاحة للمفتاح الحالي','الموديل المحفوظ رجع 404 والنظام حاول اكتشاف موديل بديل من /v1/models بدون نجاح.','راجع صلاحيات ومشروع OpenAI أو المفتاح ثم أعد اختبار OpenAI.',true];
        if($code==='social_playbook_missing')return ['Playbook إنشاء الحساب غير موجود','بيانات الحساب اتجهزت داخل Vault لكن لا يوجد Playbook create_account معتمد للمنصة المطلوبة، لذلك لم يتم تنفيذ التسجيل الخارجي ولم أسجله نجاحًا.','أضف/اعتمد Playbook إنشاء الحساب للمنصة المطلوبة ثم أعد المهمة. Gmail خصوصًا يحتاج Playbook مخصص وقد يتوقف عند تحقق الهاتف/CAPTCHA.',false];
        if($code==='browser_automation_not_configured')return ['Browser Automation غير مربوط','مسار إنشاء الحسابات الخارجية يحتاج Browser Automation Bridge فعلي، لكن URL الخاص به غير محفوظ في الخزنة.','افتح صفحة الربط واضبط Browser Automation URL/Token ثم نفّذ اختبار الاتصال. لن أسجل إنشاء Gmail/Facebook كنجاح قبل نجاح الاختبار.',true];
        if($code==='browser_automation_http_401')return ['Browser Automation رفض المصادقة','الـBridge الخاص بالمتصفح رجع HTTP 401؛ المشكلة في Token/Authorization الخاص بالـBrowser Automation وليس في Hostinger أو موديل الذكاء.','جدّد أو صحح Browser Automation Token في صفحة الربط ثم شغّل اختبار browser_automation. بعد Verified أعد أمر إنشاء الحسابات.',true];
        if($code==='browser_automation_http_403')return ['Browser Automation رفض الصلاحية','الـBridge اتعرف على الطلب لكنه رفض الصلاحية المطلوبة.','راجع Scope/Permissions للتوكن المستخدم في Browser Automation ثم اختبر الاتصال من جديد.',true];
        if($code==='browser_automation_http_404')return ['مسار Browser Automation غير صحيح','عنوان الـBridge يعمل لكن endpoint المتوقع غير موجود.','راجع URL لخدمة Browser Automation وتأكد أنها توفر /v1/test و/v1/execute.',true];
        if($code==='hostinger_http_401')return ['Hostinger رفض مفتاح API','Hostinger API رجع HTTP 401؛ المفتاح الحالي غير صالح أو انتهى/تغير.','أعد حفظ Hostinger API Token الصحيح من صفحة الربط ثم اختبر Hostinger. هذا الخطأ منفصل عن Browser Automation.',true];
        if($code==='hostinger_http_403')return ['Hostinger رفض صلاحية API','مفتاح Hostinger معروف لكن ليس له صلاحية كافية للعملية المطلوبة.','راجع صلاحيات مفتاح Hostinger أو استخدم مفتاحًا يملك النطاق المطلوب ثم اختبر الاتصال.',true];
        if($code==='hostinger_not_configured')return ['Hostinger غير مربوط','مفتاح Hostinger غير متاح للتطبيق من الخزنة المشفرة.','احفظ مفتاح Hostinger من صفحة الربط. بعد حفظه يقدر رامي يستخدمه داخليًا من غير عرض المفتاح في الدردشة.',true];
        if($code==='hostinger_site_not_found')return ['الموقع غير موجود في فهرس Hostinger','المفتاح يعمل لكن الدومين المطلوب لم يظهر ضمن المواقع المتاحة للحساب.','شغّل فهرسة Hostinger وتأكد أن الدومين تابع لنفس الحساب/المفتاح، ثم أعد أمر الإصلاح.',true];
        if($code==='hostinger_username_missing')return ['بيانات حساب الاستضافة ناقصة','الموقع موجود لكن اسم حساب الاستضافة المطلوب لواجهة الملفات غير متاح.','أعد فهرسة Hostinger لتحديث بيانات الموقع، ثم أعد المحاولة.',true];
        if($code==='meta_not_configured')return ['WhatsApp غير مكتمل الربط','Access Token أو Phone Number ID غير متاح للتطبيق.','راجع صفحة الربط واختبر Meta/WhatsApp ثم أعد المحاولة.',true];
        if($code==='meta_waba_id_missing')return ['معرف WhatsApp Business Account غير معروف','النظام لم يستقبل بعد Webhook صالح يحفظ WABA ID، لذلك لا يقدر يكتشف القوالب Approved تلقائيًا.','استقبل Webhook صحيح مرة واحدة أو اضبط اسم قالب Approved يدويًا من صفحة الربط.',true];
        if($code==='meta_template_required_outside_customer_window')return ['محتاج قالب افتتاح WhatsApp معتمد','نافذة خدمة العميل مقفولة ولا يوجد قالب Approved صالح للإرسال.','اضبط قالب Approved من صفحة الربط؛ بعدها رامي يرسله تلقائيًا ويحفظ الرسالة الأصلية لحد رد العميل.',true];
        if(str_starts_with($code,'http_401')||str_starts_with($code,'http_403'))return ['رفض صلاحية من الخدمة الخارجية','المزود رفض الطلب بسبب مفتاح/صلاحية/نطاق وصول غير كافٍ.','اختبر الاتصال من صفحة الربط، وجدّد المفتاح أو وسّع صلاحياته ثم أعد التنفيذ.',true];
        if(str_starts_with($code,'http_404')){
            $hosting=str_contains(strtolower($intent),'hosting')||str_contains(strtolower($raw),'hostinger')||str_contains(strtolower($raw),'domain')||str_contains(strtolower($raw),'file');
            return $hosting?['المسار المطلوب غير موجود','Hostinger أو خدمة الملفات لم تجد المورد/المسار المطلوب.','حدّث فهرس Hostinger وتأكد من الدومين والمسار ثم أعد الفحص.',true]:['المورد أو الموديل غير موجود','الخدمة الخارجية رجعت 404 لمورد أو موديل غير متاح؛ هذا لا يعني تلقائيًا وجود مشكلة في ملفات Hostinger.','راجع المزود والموديل المستخدمين في نفس المسار ثم أعد الاختبار.',true];
        }
        if(str_starts_with($code,'http_422'))return ['الطلب مرفوض بسبب بيانات غير صحيحة','الخدمة فهمت الطلب لكنها رفضت قيمة أو باراميتر فيه.','راجع الحقول المطلوبة والقيم المسموحة، ثم أعد الطلب بصيغة متوافقة مع واجهة الخدمة.',true];
        if(str_starts_with($code,'http_429'))return ['حد الطلبات اتجاوز','الخدمة الخارجية فعّلت Rate Limit مؤقتًا.','انتظر قليلًا ثم أعد المحاولة، وقلّل تكرار الطلبات المتزامنة.',true];
        if(str_starts_with($code,'http_5'))return ['عطل مؤقت في الخدمة الخارجية','الخدمة الخارجية رجعت خطأ من جهة السيرفر.','أعد المحاولة بعد فترة قصيرة. لو تكرر، افحص سجل الخدمة وحالة الربط.',true];
        if(str_starts_with($code,'network_error')||str_contains($raw,'timeout'))return ['مشكلة اتصال بالشبكة','الاتصال بالخدمة الخارجية لم يكتمل أو انتهت المهلة.','اختبر اتصال السيرفر وDNS/TLS ثم أعد المحاولة. لا تعتبر العملية ناجحة قبل التحقق.',true];
        if(str_starts_with($code,'permission_denied'))return ['صلاحية تشغيل ناقصة','رامي لا يملك صلاحية التشغيل المطلوبة لهذا الإجراء في قاعدة الصلاحيات.','فعّل الصلاحية التشغيلية المطلوبة لرامي ثم أعد الأمر. النسخة الحالية تمنحه صلاحيات الإصلاح التشغيلية المسموح بها ما عدا كشف الأسرار الخام.',true];
        if(str_starts_with($code,'agent_tool_disabled'))return ['أداة رامي مقفولة','الأداة المطلوبة موجودة لكنها غير مفعلة للوكيل.','فعّل الأداة من صلاحيات رامي ثم أعد التنفيذ.',true];
        if($code==='project_context_missing')return ['المشروع غير محدد','الأمر يحتاج مشروعًا أو موقعًا محددًا ولم أجد مشروعًا نشطًا واضحًا في السياق.','اذكر اسم المشروع أو الدومين مرة واحدة، وبعدها يحتفظ رامي بالسياق ويكمل عليه.',true];
        if($code==='project_database_credentials_required')return ['بيانات اتصال قاعدة المشروع ناقصة','قاعدة المشروع مكتشفة لكن كلمة مرور قاعدة البيانات غير متاحة في الخزنة.','احفظ بيانات اتصال قاعدة المشروع في الخزنة المشفرة أو استخدم إصلاح قاعدة Company OS الداخلية إن كانت هي المقصودة.',true];
        if(in_array($code,['database_plan_partial_failure','system_database_plan_partial_failure'],true))return ['خطة قاعدة البيانات توقفت جزئيًا','بعض أوامر الإصلاح اتنفذت قبل ظهور خطأ في أمر لاحق.','لا تكمل عشوائيًا. استخدم النسخة الاحتياطية المسجلة، افحص التغيير الذي تم، ثم أعد خطة أصغر بعد تصحيح الأمر الفاشل.',false];
        if($code==='php_syntax_invalid')return ['كود PHP المقترح غير صالح','فحص PHP رفض الملف قبل رفعه بسبب Syntax Error.','لا ترفع الملف. صحح الكود وأعد الفحص؛ نظام الإصلاح يمنع نشر PHP غير صالح.',true];
        if($code==='write_verification_failed')return ['فشل التحقق بعد رفع الملف','تمت محاولة رفع الملف لكن النسخة المقروءة من Hostinger لا تطابق المحتوى المطلوب.','أوقف التعديل، احتفظ بالنسخة الاحتياطية، أعد جلب الملف ثم جرّب كتابة آمنة جديدة.',true];
        if($code==='secret_file_blocked')return ['ملف أسرار محمي','مسار الملف المطلوب يحتوي إعدادات/مفاتيح حساسة والنظام يمنع رامي من قراءتها أو تعديلها مباشرة.','استخدم صفحة الربط/الخزنة المشفرة لتغيير السر، أو أصلح ملفًا غير حساس بدل كشف بيانات الاعتماد.',false];
        if($code==='sql_operation_not_allowed'||$code==='destructive_sql_blocked'||$code==='protected_system_table')return ['تعديل قاعدة البيانات مرفوض بالحماية','خطة الإصلاح تضمنت أمرًا مدمرًا أو جدولًا محميًا لا يسمح به مسار الإصلاح التلقائي.','أعد الخطة بتغيير غير مدمر. لو المطلوب حذف فعلي، استخدم مسار الحذف المحمي بأمر صريح وBackup متحقق منه.',false];
        if(str_contains($code,'backup'))return ['النسخة الاحتياطية المطلوبة غير جاهزة','الإجراء يحتاج Backup/Restore Point صالح قبل التغيير.','أنشئ نسخة احتياطية متحققة أولًا ثم أعد التنفيذ.',true];
        if($code==='ramy_action_not_supported')return ['الأمر غير مربوط بمسار تنفيذ','رامي فهم الكلام لكن النسخة الحالية لا تحتوي أداة تنفيذ لهذا النوع من الأوامر.','استخدم مسار تشخيص/إصلاح رامي في النسخة الحالية أو أضف أداة تنفيذ متخصصة بدل الاكتفاء برد نصي.',true];
        $cause=$raw!==''?AdminUi::humanError($raw):'ظهر خطأ غير مصنف أثناء التنفيذ.';
        $fix=$intent==='ramy_repair_system'||$intent==='ramy_repair_project'?'سأوقف التنفيذ عند النقطة الفاشلة، أحتفظ بالنسخ الاحتياطية وأعيد التشخيص قبل أي محاولة جديدة.':'راجع سجل النظام وجرّب مسار الإصلاح المناسب بعد تحديد السبب.';
        return ['خطأ تنفيذ غير مصنف',$cause,$fix,false];
    }

    private static function sameMeaning(string $a,string $b):bool {
        $a=pb_strtolower(preg_replace('/\s+/u',' ',trim($a))??$a);$b=pb_strtolower(preg_replace('/\s+/u',' ',trim($b))??$b);
        return $a===$b || ($a!==''&&$b!==''&&(str_contains($a,$b)||str_contains($b,$a)));
    }
}
