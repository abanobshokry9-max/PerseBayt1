# PerseBayt Browser Automation Bridge

خدمة منفصلة صغيرة لتوصيل PerseBayt بمتصفح Playwright الموجود في Codespaces/VPS.

## التشغيل

```bash
npm install
npx playwright install chromium
export BROWSER_BRIDGE_TOKEN='ضع-رمزا-طويلا-عشوائيا-هنا'
export PORT=8787
npm start
```

بعد فتح Port 8787 بعنوان HTTPS يمكن الوصول إليه من خادم PerseBayt، أدخل في **الإعدادات > الربط > Browser Automation Bridge**:

- URL: عنوان الـHTTPS العام للـBridge بدون `/v1/...`
- Token: نفس `BROWSER_BRIDGE_TOKEN`

ثم اضغط **اختبار**.

## قواعد الأمان

- لا يتم تجاوز CAPTCHA أو 2FA أو WAF؛ يرجع الـBridge `requires_human` ويتولى PerseBayt تنبيه المالك.
- يمنع الوصول إلى localhost وعناوين الشبكات الخاصة/link-local لتقليل مخاطر SSRF.
- لا يسمح بالتنزيلات.
- كلمات المرور تصل للـBridge لتنفيذ تسجيل الدخول فقط، ولا يطبعها أو يسجل Body الطلبات.
- عمليات `fill` و`submit` و`send_message` و`create_account` تظل خاضعة لموافقة المهمة داخل PerseBayt.
- استخدم الخدمة فقط للحسابات والأنظمة التي لديك صلاحية لاستخدامها، والتزم بشروط المنصات الخارجية.
