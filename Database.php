<?php
declare(strict_types=1);
/**
 * Main application PDO connection with stale-connection recovery.
 *
 * Long AI/provider calls can exceed the host's MySQL wait_timeout. Older builds kept
 * one PDO handle for the whole request, so the first DB write after a 60-90s provider
 * call could fail with MySQL 2006/2013 and was then misclassified as a provider error.
 */
final class Database {
    private static ?PDO $pdo=null;
    private static array $config=[];
    private static float $lastVerifiedAt=0.0;
    private const VERIFY_INTERVAL_SECONDS=10.0;

    public static function connection(array $c): PDO {
        if($c) self::$config=$c;
        if(self::$pdo){
            if((microtime(true)-self::$lastVerifiedAt)<self::VERIFY_INTERVAL_SECONDS) return self::$pdo;
            try{
                self::$pdo->query('SELECT 1')->fetchColumn();
                self::$lastVerifiedAt=microtime(true);
                return self::$pdo;
            }catch(Throwable $e){
                if(!self::isDisconnect($e)) throw $e;
                self::reset();
            }
        }
        return self::connect(self::$config ?: $c);
    }

    public static function reconnect(?array $c=null):PDO {
        if(is_array($c) && $c) self::$config=$c;
        self::reset();
        return self::connect(self::$config);
    }

    public static function reset():void {
        self::$pdo=null;
        self::$lastVerifiedAt=0.0;
    }

    public static function isDisconnect(Throwable|string $error):bool {
        $message=pb_strtolower($error instanceof Throwable?$error->getMessage():(string)$error);
        foreach([
            'mysql server has gone away',
            'lost connection to mysql server',
            'error: 2006',
            'error 2006',
            'error: 2013',
            'error 2013',
            'error: 2055',
            'error 2055',
            'server has gone away',
            'connection was killed',
            'connection reset by peer',
        ] as $needle) if(str_contains($message,$needle)) return true;
        if($error instanceof PDOException){
            $info=$error->errorInfo??null;
            if(is_array($info) && isset($info[1]) && in_array((int)$info[1],[2006,2013,2055],true)) return true;
        }
        return false;
    }

    private static function connect(array $c):PDO {
        foreach(['host','port','name','user','pass'] as $key) if(!array_key_exists($key,$c)) throw new RuntimeException('database_config_incomplete:'.$key);
        $dsn='mysql:host='.$c['host'].';port='.(int)$c['port'].';dbname='.$c['name'].';charset='.($c['charset']??'utf8mb4');
        $pdo=new PDO($dsn,(string)$c['user'],(string)$c['pass'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_PERSISTENT=>false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET time_zone = '+00:00'");
        self::$pdo=$pdo;
        self::$lastVerifiedAt=microtime(true);
        return self::$pdo;
    }
}
