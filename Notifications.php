<?php
declare(strict_types=1);
final class Notifications {
    public static function add(string $severity,string $category,string $title,string $body='',?string $entityType=null,?string $entityId=null):int{
        if(!in_array($severity,['info','success','warning','critical'],true))$severity='info';$q=db()->prepare('INSERT INTO notifications(severity,category,title,body_text,entity_type,entity_id) VALUES (?,?,?,?,?,?)');$q->execute([$severity,$category,$title,$body,$entityType,$entityId]);return (int)db()->lastInsertId();
    }
    public static function unread(int $limit=12):array{$q=db()->prepare('SELECT * FROM notifications WHERE read_at IS NULL ORDER BY id DESC LIMIT '.max(1,min(50,$limit)));$q->execute();return $q->fetchAll();}
    public static function count():int{return (int)db()->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn();}
    public static function mark(int $id):void{db()->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=?')->execute([$id]);}
}
