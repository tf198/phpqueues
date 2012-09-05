<?php
require "job_common.php";

$pid = getmypid();
echo "Creator {$pid} started\n";

$jobs = new ConcurrentFIFO(JOBS_IN);
$i=0;

while(file_exists(JOBS_LOCK)) {
	$jobs->enqueue($pid . ":" . $i++);
	usleep(rand(400000,00000));
}