<?php
require __DIR__ . '/inc/core.php';
$_SESSION = [];
session_destroy();
header('Location: ./');
