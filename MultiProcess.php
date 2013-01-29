<?php
require_once "Deferred.php";

define('MULTIPROCESS_PID', getmypid());

class Multiprocess {

	public static $level = LOG_NOTICE;
	
	static function log($message, $level=LOG_INFO) {
		if( $level > self::$level ) return;
		fprintf(STDERR, "[%4d] %s\n", MULTIPROCESS_PID, $message);
	}
	
	static function send($stream, $data) {
		$buf = serialize($data);
		fwrite($stream, pack("V", strlen($buf)));
		fwrite($stream, $buf);
	}
	
	static function receive($stream) {
		$buf = fread($stream, 4);
		if(strlen($buf) != 4) return false;
		$m = unpack("V", $buf);
		return unserialize(fread($stream, $m[1]));
	}
}

class MultiProcessManager {
	
	private $bootstrap;
	
	private $num_workers;
	
	private $workers = array();
	
	private $queue = array();
	
	private $allocated = array();
	
	private $running;
	
	function __construct($bootstrap, $workers=2, $allowable_errors=10) {
		$this->bootstrap = realpath($bootstrap);
		if(!$this->bootstrap) throw new Exception("Cannot locate '{$bootstrap}'");
		
		// prevent eternal loops
		$this->allowable_errors = $allowable_errors;
		
		MultiProcess::log("Starting {$workers} workers");
		for($i=0; $i<$workers; $i++) $this->add_worker();
	}
	
	function add_worker() {
		$worker = new MultiProcessWorker($this->bootstrap);
		$status = $worker->get_status();
		$this->workers[$status['pid']] = $worker;
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
		while($this->running) {
			
			$streams = array();
			$stream_hash = array();
			
			foreach(array_keys($this->workers) as $pid) {
				$status = $this->workers[$pid]->get_status();
				$stream = null;
				
				if($status['running']) {
					if(isset($this->allocated[$pid])) {
						$stream = $this->workers[$pid]->get_stdout();
					} else {
						// allocate a job if one is available
						$job = array_shift($this->queue);
						if($job) {
							MultiProcess::log("Assiging work to {$pid}", LOG_DEBUG);
							$this->allocated[$pid] = $job;
							MultiProcess::send($this->workers[$pid]->get_stdin() , $job[0]);
							$stream = $this->workers[$pid]->get_stdout();
						}
					}
				} else {
					// worker has died for some reason
					MultiProcess::log("Worker {$pid} died - RIP!");
					$this->allowable_errors--;
					
					if($this->allowable_errors <= 0) {
						MultiProcess::log("Too many errors, shutting down", LOG_ERR);
						$this->running = false;
						continue;
					}
					
					unset($this->workers[$pid]);
					// requeue the job if required
					if(isset($this->allocated[$pid])) {
						array_unshift($this->queue, $this->allocated[$pid]);
						unset($this->allocated[$pid]);
					}
					// start a new worker to take its place
					$this->add_worker();
				}
				
				// add the stream to the select() list
				if($stream !== null) {
					$streams[] = $stream;
					$stream_hash[$stream] = $pid;
				}
			}
				
			MultiProcess::log(count($streams) . " workers busy", LOG_DEBUG);
			
			// do the actual stream reading
			if($streams) {
				$ready = stream_select($streams, $write=null, $error=null, 30);
				
				foreach($streams as $stream) {
					$result = MultiProcess::receive($stream);
					
					if($result) {
						$pid = $stream_hash[$stream];
						
						list($job, $d) = $this->allocated[$pid];
						
						$result['job'] = $job;
						$result['pid'] = $pid;
						
						if($result['status'] == 'ok') {
							$d->callback($result['result']);
						} else {
							$d->errback($result['exception']);
						}
						unset($this->allocated[$pid]);
					}
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
		
		MultiProcess::log("Everything cleanly shutdown", LOG_DEBUG);
	}
}

/**
 * Process wrapper
 * @author tris
 *
 */
class MultiProcessWorker {
	
	private static $descriptors = array(
		0 => array("pipe", "r"), // child process STDIN
		1 => array("pipe", "w"), // child process STDOUT
		2 => STDERR,
	);
	
	private $streams;
	
	private $p;
	
	function __construct($bootstrap) {
		$cmd = sprintf("php %s %s", __FILE__, escapeshellarg($bootstrap));
		$this->p = proc_open($cmd, self::$descriptors, $this->streams);
		
		if(!is_resource($this->p)) throw new Exception("Failed to open process: {$cmd}");
	}
	
	function get_stdin() {
		return $this->streams[0];
	}
	
	function get_stdout() {
		return $this->streams[1];
	}
	
	function get_status() {
		return proc_get_status($this->p);
	}
	
	function close() {
		fclose($this->get_stdin());
		return proc_close($this->p);
	}
}

// use this file as the dispatcher
if( __FILE__ != realpath($_SERVER['SCRIPT_FILENAME']) ) return;

if( $argc < 2 ) die("Not enough arguments\n");

MultiProcess::log("Loading bootstrap: {$argv[1]}", LOG_DEBUG);
require_once $argv[1];

while($params = MultiProcess::receive(STDIN)) {
	$callback = array_shift($params);

	$result = array('child_pid' => MULTIPROCESS_PID);
	
	try {
		MultiProcess::log("Executing: " . print_r($callback, true), LOG_DEBUG);
		$result['result'] = call_user_func_array($callback, $params);
		$result['status'] = 'ok';
	} catch(Exception $e) {
		$result['status'] = 'failed';
		$result['exception'] = $e;
	}
	MultiProcess::log("Result: {$result['status']}", LOG_DEBUG);
		
	MultiProcess::send(STDOUT, $result);
}

MultiProcess::log("Finished", LOG_DEBUG);

