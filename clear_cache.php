<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPCache has been reset.<br>";
} else {
    echo "OPCache is not enabled or not found.<br>";
}

session_start();
session_destroy();
echo "All sessions have been cleared.<br>";
echo "<a href='index.php'>Back to Home</a>";
?>