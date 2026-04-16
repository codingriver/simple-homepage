<?php
require "/var/www/nav/admin/shared/webdav_lib.php";
header("Content-Type: text/plain");
echo "HTTP_X_REAL_IP: [" . ($_SERVER["HTTP_X_REAL_IP"] ?? "(not set)") . "]\n";
echo "REMOTE_ADDR: [" . ($_SERVER["REMOTE_ADDR"] ?? "(not set)") . "]\n";
echo "webdav_client_ip: [" . webdav_client_ip() . "]\n";
