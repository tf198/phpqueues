<?php
require 'job_common.php';

$output = new ConcurrentFIFO(JOBS_OUT);

$workers = array();
$i=0;
while(file_exists(JOBS_LOCK)) {
	while($data = $output->dequeue()) {
		$parts = explode(':', $data);
		$pid = $parts[0];
		if(!isset($workers[$pid])) $workers[$pid] = array(0, 0);
		if($parts[1]) {
			$workers[$pid][0]++;
		} else {
			$workers[$pid][1]++;
		}
	}
	
	sleep(2);
	
	//if($i>=10) {
		echo PHP_EOL . PHP_EOL;
		foreach($workers as $worker=>$results) {
			fprintf(STDERR, "%5d %5d [%3d timeouts]\n", $worker, $results[0], $results[1]);
		}
		clearstatcache();
		fprintf(STDERR, "\nIN QUEUE : %d\n", filesize(JOBS_IN));
		fprintf(STDERR, "OUT QUEUE: %d\n", filesize(JOBS_OUT));
		$i=0;
	//}
	//$i++;
}