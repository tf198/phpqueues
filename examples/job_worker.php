<?php
require "job_common.php";

$pid = getmypid();
echo "Worker {$pid} started\n";

$input = new ConcurrentFIFO(JOBS_IN);
$output = new ConcurrentFIFO(JOBS_OUT);
$timeout = 5;

while(file_exists(JOBS_LOCK)) {
	$data = $input->bdequeue($timeout);
	if($data) {
		usleep(rand(100000, 200000));
		$output->enqueue("{$pid}:{$data}");
		if($data == 'quit') break;
	} else {
		$output->enqueue("{$pid}:0");
	}
}