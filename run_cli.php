<?php
header('Content-Type: text/plain');

function run_cmd($cmd) {
    echo "=== Running: $cmd ===\n";
    $output = [];
    $retval = -1;
    exec($cmd . ' 2>&1', $output, $retval);
    echo implode("\n", $output) . "\n";
    echo "Exit Code: $retval\n\n";
}

run_cmd('whoami');
run_cmd('ls -la /home/ubuntu');
run_cmd('ls -la /home/ubuntu/moodle-project');
run_cmd('find /home/ubuntu -maxdepth 2 -name "*.sh"');
run_cmd('crontab -l');
run_cmd('sudo -n crontab -l');
run_cmd('cat /etc/cron.d/*');
