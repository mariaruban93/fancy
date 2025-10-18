<?php
// Logout script: destroys the session and redirects to login page
session_start();
session_destroy();
header('Location: index.php');
exit;