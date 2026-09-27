<?php
declare(strict_types=1);

final class EmailConnector
{
    private static function transport(): string
    {
        $v = strtolower(trim((string)config('connections.email.transport', 'api')));
        return in_array($v, ['api','php_mail'], true) ? $v : 'api';
    }
    private static function apiUrl(): string { return trim((string)config('connections.email.api_url','')); }
    private static function token(): string { return trim((string)config('connections.email.token','')); }
    private static function fromEmail(): string { return trim((string)config('connections.email.from_email','')); }
    private static function fromName(): string { return trim((string)config('connections.email.from_name','شركة المتر')); }
    private static function replyTo(): string { return trim((string)config('connections.email.reply_to','')); }

    public static function configured(): bool
    {
        if (!filter_var(self::fromEmail(), FILTER_VALIDATE_EMAIL)) return false;
        if (self::transport() === 'php_mail') return function_exists('mail');
        return (bool)filter_var(self::apiUrl(), FILTER_VALIDATE_URL);
    }

    public static function send(string $to, string $subject, string $body): array
    {
        $to = trim($to); $subject = trim($subject); $body = trim($body);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('customer_email_invalid');
        if (!self::configured()) throw new RuntimeException('email_not_configured');
        if ($subject === '') $subject = 'شركة المتر';
        if ($body === '') throw new RuntimeException('message_body_required');

        if (self::transport() === 'api') {
            $headers = ['X-Elmetr-Connector'=>'email'];
            if (self::token() !== '') $headers['Authorization'] = 'Bearer '.self::token();
            $payload = [
                'from' => ['email'=>self::fromEmail(),'name'=>self::fromName()],
                'to' => [['email'=>$to]],
                'subject' => pb_substr($subject,0,250),
                'text' => pb_substr($body,0,20000),
                'reply_to' => filter_var(self::replyTo(), FILTER_VALIDATE_EMAIL) ? self::replyTo() : self::fromEmail(),
                'metadata' => ['source'=>'persebayt','release'=>ReleaseInfo::VERSION],
            ];
            $r = HttpClient::json('POST', Security::publicUrl(self::apiUrl()), $headers, $payload, 35);
            return ['accepted'=>true,'transport'=>'api','id'=>(string)($r['id']??$r['message_id']??$r['data']['id']??''),'response'=>$r];
        }

        $fromName = str_replace(["\r","\n"],' ',self::fromName());
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: '.$fromName.' <'.self::fromEmail().'>',
            'Reply-To: '.(filter_var(self::replyTo(), FILTER_VALIDATE_EMAIL) ? self::replyTo() : self::fromEmail()),
            'X-Mailer: PerseBayt/'.ReleaseInfo::VERSION,
        ];
        $ok = @mail($to, '=?UTF-8?B?'.base64_encode(pb_substr($subject,0,250)).'?=', $body, implode("\r\n",$headers));
        if (!$ok) throw new RuntimeException('php_mail_rejected');
        return ['accepted'=>true,'transport'=>'php_mail','id'=>'mail-'.substr(hash('sha256',$to.'|'.$subject.'|'.microtime(true)),0,18)];
    }

    public static function diagnostics(): array
    {
        return [
            'configured'=>self::configured(),
            'transport'=>self::transport(),
            'api_url'=>self::apiUrl()!=='' ? true : false,
            'from_email'=>filter_var(self::fromEmail(), FILTER_VALIDATE_EMAIL) ? self::fromEmail() : null,
        ];
    }
}
