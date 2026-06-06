<?php
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status();
    if ($status === false) {
        echo "OPCache is disabled.";
    } else {
        echo "OPCache is enabled." . PHP_EOL;
        if (isset($status['scripts'])) {
            $file = 'C:\xampp\htdocs\PEPO\staff\side_inventory.php';
            if (isset($status['scripts'][$file])) {
                echo "File $file is cached in OPCache." . PHP_EOL;
                echo "Last used: " . date('Y-m-d H:i:s', $status['scripts'][$file]['last_used_timestamp']) . PHP_EOL;
            } else {
                echo "File $file is NOT in OPCache scripts." . PHP_EOL;
            }
        }
    }
} else {
    echo "OPCache extension not found.";
}
?>