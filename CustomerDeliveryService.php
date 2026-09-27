<?php
declare(strict_types=1);
final class CustomerDeliveryService {
    public static function onProjectApproved(int $projectId):void{
        $q=db()->prepare("SELECT * FROM quotes WHERE project_id=? AND owner_approved=1 AND status='accepted' ORDER BY id DESC LIMIT 1");$q->execute([$projectId]);$quote=$q->fetch();if(!$quote)return;
        if(empty($quote['delivery_ready_at']))db()->prepare('UPDATE quotes SET delivery_ready_at=NOW() WHERE id=?')->execute([$quote['id']]);
        self::tryDeliver((int)$quote['id']);
    }
    public static function onPaymentConfirmed(int $quoteId):void{self::tryDeliver($quoteId);}
    public static function tryDeliver(int $quoteId):void{
        $q=db()->prepare('SELECT q.*,p.primary_domain,p.name project_name FROM quotes q LEFT JOIN projects p ON p.id=q.project_id WHERE q.id=?');$q->execute([$quoteId]);$quote=$q->fetch();
        if(!$quote||empty($quote['delivery_ready_at'])||!empty($quote['delivered_at']))return;
        $p=db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE quote_id=? AND status='confirmed'");$p->execute([$quoteId]);$paid=(float)$p->fetchColumn();$total=(float)$quote['amount'];
        if($paid+0.0001<$total){Notifications::add('info','delivery','المشروع جاهز وينتظر باقي المبلغ','اعتمد عماد المشروع، والمتبقي المؤكد قبل التسليم: '.number_format(max(0,$total-$paid),2).' '.AdminUi::currency((string)$quote['currency']).'.','quote',(string)$quoteId);return;}
        $project=$quote['project_id']?ProjectService::get((int)$quote['project_id']):null;if($project&&StagingService::eligible($project)&&StagingService::needsPromotion((int)$project['id'])){Notifications::add('info','delivery','المشروع معتمد وينتظر النقل النهائي','عماد اعتمد نسخة Staging، لكن لم يتم نقلها بعد إلى دومين العميل النهائي. رامي يمكنه تنفيذ النقل بعد تأكيد الدومين النهائي.','quote',(string)$quoteId);return;}$domain=trim((string)$quote['primary_domain']);if($domain===''){Notifications::add('warning','delivery','التسليم متوقف لعدم وجود دومين','المبلغ مكتمل والمراجعة معتمدة، لكن المشروع لا يملك دومين نهائيًا للتسليم.','quote',(string)$quoteId);return;}
        $body='تم الانتهاء من المشروع واعتماده بعد المراجعة، وتم تأكيد اكتمال المبلغ. رابط المشروع: https://'.$domain.'/';
        $send=CommunicationGateway::outboundCustomer((int)$quote['customer_id'],$body);if(empty($send['sent']))return;
        db()->prepare('UPDATE quotes SET delivered_at=NOW() WHERE id=? AND delivered_at IS NULL')->execute([$quoteId]);db()->prepare("UPDATE customers SET status='completed' WHERE id=?")->execute([$quote['customer_id']]);
        Audit::log('agent',(string)AgentService::bySlug('ramy')['id'],'customer.project_delivered','quote',(string)$quoteId,'verified',$quote['project_id']?(int)$quote['project_id']:null,null,['channel'=>$send['channel']??null]);
        Notifications::add('success','delivery','تم تسليم المشروع للعميل','تم إرسال رابط المشروع بعد اعتماد عماد وتأكيد كامل المبلغ.','quote',(string)$quoteId);
    }
}
