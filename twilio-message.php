<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
$url=(string)config('app.base_url').'/webhooks/twilio-message.php';$params=$_POST;$sig=(string)($_SERVER['HTTP_X_TWILIO_SIGNATURE']??'');if(!TwilioClient::verify($url,$params,$sig)){http_response_code(401);echo 'invalid';exit;}
$sid=(string)($_POST['MessageSid']??'');$from=(string)($_POST['From']??'');$body=trim((string)($_POST['Body']??''));$channel=str_starts_with($from,'whatsapp:')?'whatsapp':'sms';$clean=preg_replace('/^whatsapp:/','',$from);try{if($body!=='')CommunicationGateway::inbound($channel,'twilio',$clean,$body,$sid,$_POST);}catch(Throwable $e){Notifications::add('critical','communications','فشل استقبال رسالة Twilio',AdminUi::humanError($e->getMessage()),'message',$sid);}header('Content-Type: text/xml; charset=UTF-8');echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
