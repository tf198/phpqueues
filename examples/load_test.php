<?
require "job_common.php";

define('WORKERS', 5);
define('CREATORS', 2);

$processes = array();

@unlink(JOBS_IN);
@unlink(JOBS_OUT);

$path = realpath(dirname(__FILE__));

for($i=0; $i<CREATORS; $i++) {
	$processes[] = proc_open("php {$path}/job_creator.php", array(), $pipes);
}
for($i=0; $i<WORKERS; $i++) {
	$processes[] = proc_open("php {$path}/job_worker.php", array(), $pipes);
}
sleep(1);

echo "\nRun job_manager.php to track output\n";
echo "Press ENTER to stop...";
fgets(STDIN);
echo "\n\nCleaning up...\n";

unlink(JOBS_LOCK);