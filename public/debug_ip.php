<?php
header("Content-Type: text/plain");
echo "HTTP_X_REAL_IP: [" . ($_SERVER["HTTP_X_REAL_IP"] ?? "(not set)") . "]\n";
echo "REMOTE_ADDR: [" . ($_SERVER["REMOTE_ADDR"] ?? "(not set)") . "]\n";
