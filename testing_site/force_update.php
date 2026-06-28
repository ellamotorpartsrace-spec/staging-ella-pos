<?php
header("Content-Type: text/plain");
echo "Forcing update via git pull...\n\n";

$output = shell_exec('git reset --hard HEAD 2>&1 && git pull origin testing 2>&1');
echo $output;

echo "\n\nDone! Now visit sync_inventory.php and click Apply.";
