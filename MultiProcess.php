<?php
require_once "Deferred.php";
require_once "ConcurrentFIFO.php";

define('MULTIPROCESS_PID', getmypid());
define('MP_WORKER_TIMEOUT', 10);

define('MP_DATA_DIR', 'data');

class Multiprocess {

	public static $level = LOG_DEBUG;
	
	static function log($message, $level=LOG_INFO) {
		if( $level > self::$level ) return;
		
		if(is_array($message)) {
			$message = print_r($message, true);
		}
		
		fprintf(STDERR, "[%4d] %s\n", MULTIPROCESS_PID, $message);
	}
}

class MultiProcessManager {
	
	private $bootstrap;
	
	private $num_workers;
	
	private $queue = array();
	
	private $allocated = array();
	
	private $workers = array();
	
	private $running;
	
	function __construct($bootstrap, $workers=2, $allowable_errors=10, $data_dir=MP_DATA_DIR) {
		
		if(!is_dir($data_dir) && !mkdir($data_dir)) {
			throw new Exception("Failed to create data dir");
		}
		
		if(!is_writeable($data_dir)) {
			throw new Exception("{$data_dir} is not writable");
		}
		
		$this->bootstrap = realpath($bootstrap);
		if(!$this->bootstrap) throw new Exception("Cannot locate '{$bootstrap}'");
		
		$this->manager_fifo = tempnam($data_dir, 'mp-');
		Multiprocess::log("Manager FIFO: {$this->manager_fifo}");
		
		// prevent eternal loops
		$this->allowable_errors = $allowable_errors;
		
		MultiProcess::log("Starting {$workers} workers");
		for($i=0; $i<$workers; $i++) $this->add_worker();
	}
	
	function add_worker() {
		$worker = new MultiProcessWorker($this->bootstrap, $this->manager_fifo);
		$this->workers[$worker->id()] = $worker;
	}
	
	function defer($callable) {
		$params = func_get_args();
		$d = new Deferred();
		$this->queue[] = array($params, $d);
		return $d;
	}
	
	function shutdown() {
		$this->running = false;
	}
	
	function process() {
		$this->running = true;
		
		MultiProcess::log("Starting to process jobs");
		
		$recv_q = new ConcurrentFIFO($this->manager_fifo);
		MultiProcess::log("Waiting for results on {$recv_q}", LOG_DEBUG);
		
		// allow the reactor to set an exception to be thrown
		$e = null;
		
		while($this->running) {
			
			foreach($this->workers as $wid=>$worker) {
				$status = $worker->get_status();
				
				// Check if worker is still alive
				if($status['running']) {
					
					// check if worker is idle
					if(!isset($this->allocated[$wid])) {
						// allocate a job if one is available
						$job = array_shift($this->queue);
						if($job) {
							MultiProcess::log("Assiging work to worker {$wid}", LOG_DEBUG);
							$this->allocated[$wid] = $job;
							//MultiProcess::send($this->workers[$pid]->get_stdin() , $job[0]);
							$worker->send($job[0]);
						}
					}
					
				} else {
					// worker has died for some reason
					MultiProcess::log("Worker {$wid} died - RIP!", LOG_WARNING);
					$this->allowable_errors--;
					
					// if we lose too many processes there is probably a coding error
					if($this->allowable_errors <= 0) {
						MultiProcess::log("Too many errors, shutting down", LOG_ERR);
						$this->running = false;
						$e = new Exception("Too many errors");
						continue;
					}
					
					$worker->close();
					unset($this->workers[$wid]);
					// requeue the job if required
					if(isset($this->allocated[$wid])) {
						array_unshift($this->queue, $this->allocated[$wid]);
						unset($this->allocated[$wid]);
					}
					// start a new worker to take its place
					$this->add_worker();
				}
				
			}
				
			MultiProcess::log(count($this->allocated) . " workers busy", LOG_DEBUG);
			
			// check for results
			
			if($this->allocated) {
				$data = $recv_q->bdequeue(5);
					
				if($data) {
					$result = unserialize($data);
					#Multiprocess::log($result);
					$wid = $result['worker_id'];
						
					list($job, $d) = $this->allocated[$wid];
						
					if($result['status'] == 'ok') {
						$d->callback($result['result']);
					} else {
						$d->errback($result['exception']);
					}
					unset($this->allocated[$wid]);
				}
			} else {
				if(!$this->queue) {
					MultiProcess::log("No more work", LOG_DEBUG);
					break;
				}
			}
		}
		
		MultiProcess::log("Shutting down");
		// try and shut down everything cleanly
		foreach($this->workers as $worker) {
			$worker->close();
		}
		
		$recv_q->delete();
		
		if($e) throw $e;
		
		MultiProcess::log("Everything cleanly shutdown", LOG_DEBUG);
	}
}

/**
 * Process wrapper
 * @author tris
 *
 */
class MultiProcessWorker {
	
	private $p;
	
	private $send_q, $worker_id;
	
	static $_next_id=0;
	
	function __construct($bootstrap, $manager_fifo) {
		$manager = MULTIPROCESS_PID;
		
		$this->worker_id = self::$_next_id++;
		
		$cmd = sprintf("php %s %s %s %d", __FILE__, escapeshellarg($bootstrap), escapeshellarg($manager_fifo), $this->worker_id);
		$this->p = proc_open($cmd, array(), $streams);
		
		if(!is_resource($this->p)) throw new Exception("Failed to open process: {$cmd}");
		
		$this->send_q = new ConcurrentFIFO("{$manager_fifo}-{$this->worker_id}");
		MultiProcess::log("Send queue for worker {$this->worker_id}: {$this->send_q}", LOG_DEBUG);
	}
	
	function get_status() {
		return proc_get_status($this->p);
	}
	
	function id() {
		return $this->worker_id;
	}
	
	function send($data) {
		$data = serialize($data);
		#MultiProcess::log("Sending data: {$data}");
		$this->send_q->enqueue($data);
	}

	function close() {
		$status = $this->get_status();
		Multiprocess::log("Closing process {$status['pid']}");
		$this->send_q->enqueue("SIG_QUIT");
		$result = proc_close($this->p);
		
		$this->send_q->delete();
		return $result;
	}
}

#### END OF CLASSES ###

// use this file as the dispatcher
if( __FILE__ != realpath($_SERVER['SCRIPT_FILENAME']) ) return;

if( $argc < 4 ) die("Not enough arguments\n");

$bootstrap = $argv[1];
$basepath = dirname($bootstrap);
set_include_path(get_include_path() . PATH_SEPARATOR . $basepath);
chdir($basepath);

require_once $bootstrap;
#MultiProcess::log("Loaded bootstrap: {$bootstrap}", LOG_DEBUG);

$manager_fifo = $argv[2];
$worker_id = $argv[3];

$jobs_q = new ConcurrentFIFO("{$manager_fifo}-{$worker_id}");
$results_q = new ConcurrentFIFO($manager_fifo);

#MultiProcess::log("Receive queue: {$jobs_q}", LOG_DEBUG);
#MultiProcess::log("Results queue: {$results_q}", LOG_DEBUG);

MultiProcess::log("Worker {$worker_id} ready for jobs");
while($data = $jobs_q->bdequeue(MP_WORKER_TIMEOUT)) {
	
	#MultiProcess::log("Got data: {$data}");
	
	if(!$data) {
		MultiProcess::log("Worker {$worker_id} received no work in " . MP_WORKER_TIMEOUT . " seconds - quitting...", LOG_WARNING);
		break;
	}
	
	if(substr($data, 0, 3) == 'SIG') {
		MultiProcess::log("Worker {$worker_id} received {$data}", LOG_DEBUG);
		switch(substr($data, 4)) {
			case 'QUIT':
				break 2;
			default:
				MultiProcess::log("Unknown signal!", LOG_WARNING);
		}
	}
	
	$params = unserialize($data);
	$callback = array_shift($params);

	$result = array('child_pid' => MULTIPROCESS_PID, 'worker_id' => $worker_id);
	
	// need to make sure nothing else writes to STDOUT
	ob_start();
	try {
		#MultiProcess::log(sprintf("Worker %d: %s(%s)", $worker_id, print_r($callback, true), implode(', ', $params)), LOG_DEBUG);
		MultiProcess::log(sprintf("Worker %d: %s()", $worker_id, print_r($callback, true)), LOG_DEBUG);
		$result['result'] = call_user_func_array($callback, $params);
		$result['status'] = 'ok';
	} catch(Exception $e) {
		$result['status'] = 'failed';
		$result['exception'] = $e;
	}
	MultiProcess::log("Result: {$result['status']}", LOG_DEBUG);
	
	$data = ob_get_clean();
	if($data) fwrite(STDERR, $data);
		
	$results_q->enqueue(serialize($result));
}

MultiProcess::log("Worker {$worker_id} Finished", LOG_DEBUG);

