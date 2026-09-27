<?php
declare(strict_types=1);require_once __DIR__.'/src/bootstrap.php';if(Auth::user())header('Location: /api/admin/');else header('Location: /api/admin/login.php');
