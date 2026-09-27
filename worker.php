<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/public_html/api/src/bootstrap.php';
$limit=(int)($argv[1]??10);$rows=Worker::run($limit);echo j(['ok'=>true,'jobs'=>$rows,'at'=>date(DATE_ATOM)]).PHP_EOL;
