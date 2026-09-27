<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::start();
if(Auth::user()){header('Location: /api/admin/');exit;}
$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        Auth::verifyCsrf();
        $login=trim((string)($_POST['login']??''));
        $pass=(string)($_POST['password']??'');
        if(Auth::login($login,$pass)){header('Location: /api/admin/');exit;}
        $err='بيانات الدخول غير صحيحة.';
    }catch(Throwable $e){
        $err=$e->getMessage()==='login_rate_limited'?'محاولات دخول كثيرة. جرّب بعد 15 دقيقة.':'تعذر تسجيل الدخول بأمان.';
    }
}
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#07130d">
<title>دخول الإدارة | المتر</title>
<link rel="stylesheet" href="/api/admin/assets/app.css?v=820">
</head>
<body class="login-page-v51">
<main class="login-shell login-shell-v51">
    <div class="login-glow login-glow-a" aria-hidden="true"></div>
    <div class="login-glow login-glow-b" aria-hidden="true"></div>
    <form method="post" class="card login-card login-card-v51" autocomplete="on" novalidate>
        <?=AdminUi::csrf()?>
        <header class="login-hero-v51">
            <span class="login-logo-v51" aria-hidden="true">م</span>
            <div>
                <span class="login-kicker-v51">نظام الشركة · الإصدار 20.2.0</span>
                <h1>مساحة عمل المتر</h1>
                <p>دخول المالك إلى الوكلاء والمشاريع والمحادثات.</p>
            </div>
        </header>
        <div class="login-status-v51" aria-label="حالة الدخول">
            <span><i></i> جلسة محمية</span>
            <span>تسجيل محاولات الدخول</span>
        </div>
        <?php if($err):?><div class="flash error login-error-v51" role="alert"><?=e($err)?></div><?php endif?>
        <div class="field login-field-v51">
            <label for="login">البريد أو الهاتف</label>
            <div class="login-input-wrap-v51"><span aria-hidden="true">⌁</span><input id="login" name="login" autocomplete="username" inputmode="email" required autofocus placeholder="اكتب البريد أو رقم الهاتف"></div>
        </div>
        <div class="field login-field-v51">
            <label for="password">كلمة المرور</label>
            <div class="login-input-wrap-v51"><span aria-hidden="true">●</span><input id="password" type="password" name="password" autocomplete="current-password" required placeholder="••••••••"><button type="button" class="password-toggle-v51" id="passwordToggle" aria-label="إظهار كلمة المرور">عرض</button></div>
        </div>
        <button class="btn full-width login-submit-v51" type="submit"><span>دخول آمن</span><b aria-hidden="true">←</b></button>
        <p class="muted small login-note login-note-v51">الدخول مخصص للمالك. لن يتم عرض مفاتيح واجهات الربط أو كلمات المرور الخام داخل الواجهة.</p>
    </form>
</main>
<script>
(()=>{const p=document.getElementById('password'),b=document.getElementById('passwordToggle');if(!p||!b)return;b.addEventListener('click',()=>{const show=p.type==='password';p.type=show?'text':'password';b.textContent=show?'إخفاء':'عرض';b.setAttribute('aria-label',show?'إخفاء كلمة المرور':'إظهار كلمة المرور');});})();
</script>
</body>
</html>
