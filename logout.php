<?php
declare(strict_types=1);require_once dirname(__DIR__).'/src/bootstrap.php';Auth::logout();header('Location: /api/admin/login.php');
