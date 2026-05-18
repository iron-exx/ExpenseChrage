<?php
/* Redirect to admin/setup.php — this file is kept for backwards compatibility */
header('Location: '.str_replace('admin.php', 'admin/setup.php', $_SERVER['REQUEST_URI']));
exit;
?>
