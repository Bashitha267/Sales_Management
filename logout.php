<?php
session_start();
session_unset();
session_destroy();
header("Location: /ref/login.php");
exit;
?>