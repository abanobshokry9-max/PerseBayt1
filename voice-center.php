<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
Auth::requireOwner();
header('Location: /api/admin/voice.php', true, 302);
exit;
