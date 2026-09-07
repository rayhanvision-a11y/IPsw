<?php
@error_reporting(0);
@ini_set('display_errors', '0');
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
echo json_encode(['loggedIn' => !empty($_SESSION['loggedIn'])]);
