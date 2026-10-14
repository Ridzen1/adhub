<?php
require_once __DIR__ . '/../inc/access.php';
logout();
header('Location: /pages/login.php');
exit;
