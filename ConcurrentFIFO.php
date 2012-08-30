<?php
/**
* FIFO specifically designed for concurrent access - think IPC,
* processing queues etc.  Can do over 35K/s enqueue() and 21K/s 
* dequeue() with guaranteed atomicicity.
*
* Data is written sequencially to the file and the first item pointer is advanced
* as items are dequeued.  The file is truncated when all items have been dequeued and
* compacted when the the wasted space is greater than 8K so the file should remain a
* reasonable size as long as the number of dequeues > enqueues over time.
*/
class ConcurrentFIFO {
	
	private $fp;
	
	const OFFSET_FORMAT = 'V';
	const OFFSET_SIZE = 4;
	
	const LENGTH_FORMAT = 'v';
	const LENGTH_SIZE = 2;
	
	const BUFSIZ = 8192;
	const COMPACT_AT = 8192; // allow 8K before truncating
	
	public $poll_frequency = 100000; // microseconds
	
	function __construct($filename) {
		$this->fp = fopen($filename, 'cb+');
		if(!$this->fp) throw new Exception("Failed to open '{$filename}'");
	}
	
	function _read_int($format, $size) {
		$buffer = fread($this->fp, $size);
		if(!$buffer) return 0;
		$u = unpack($format, $buffer);
		return $u[1];
	}
	/*
	function lock($type) {
		for($i=0; $i<100; $i++) {
			if(flock($this->fp, $type)) return;
			fputs(STDERR, '!');
			usleep(rand(0, 100));
		}
		throw new Exception('Failed to get lock');
	}
	
	function unlock() {
		flock($this->fp, LOCK_UN);
	}
	*/
	function dequeue() {
		// need an exclusive lock
		flock($this->fp, LOCK_EX) or die('Failed to get lock');
		fseek($this->fp, 0);
		$offset = $this->_read_int(self::OFFSET_FORMAT, self::OFFSET_SIZE);
		
		if($offset) {
			// read the length and get the data
			fseek($this->fp, $offset);
			$l = $this->_read_int(self::LENGTH_FORMAT, self::LENGTH_SIZE);
			$data = fread($this->fp, $l);
			
			$p = ftell($this->fp);
			// try a read to see if there is any more data
			if(fread($this->fp, 1)) {
				if($offset > self::COMPACT_AT) {
					$p = $this->_compact($p);
				}
				// write the new offset
				fseek($this->fp, 0);
				fwrite($this->fp, pack(self::LENGTH_FORMAT, $p), self::LENGTH_SIZE);
			} else {
				// can just truncate the whole file
				ftruncate($this->fp, 0);
			}
		} else {
			$data = null;
		}
		flock($this->fp, LOCK_UN);
		return $data;
	}
	
	function enqueue($data) {
		$data = (string) $data;
		$c = strlen($data);
		
		// get exclusive lock
		flock($this->fp, LOCK_EX) or die('Failed to get lock');
		// seek to end
		fseek($this->fp, 0, SEEK_END);
		
		if(ftell($this->fp) == 0) {
			// empty fifo - write the offset
			fwrite($this->fp, pack(self::OFFSET_FORMAT, self::OFFSET_SIZE), self::OFFSET_SIZE);
		}
		
		// write length followed by data
		fwrite($this->fp, pack(self::LENGTH_FORMAT, $c), self::LENGTH_SIZE);
		fwrite($this->fp, $data, $c);
		// release lock
		flock($this->fp, LOCK_UN);
	}
	
	function is_empty() {
		flock($this->fp, LOCK_SH) or die('Failed to get lock');
		fseek($this->fp, 0, SEEK_END);
		$p = ftell($this->fp);
		flock($this->fp, LOCK_UN);
		return ($p == 0);
	}
	
	function clear() {
		flock($this->fp, LOCK_EX) or die('Failed to get lock');
		ftruncate($this->fp, 0);
		flock($this->fp, LOCK_UN);
	}
	
	function _compact($p) {
		// truncate start
		$p_current = $p;
		$p_new = self::OFFSET_SIZE;
		
		fseek($this->fp, $p_current);
		while($buffer = fread($this->fp, self::BUFSIZ)) {
			fseek($this->fp, $p_new);
			$c = strlen($buffer);
			//echo "Writing {$c} bytes from {$p_current} to {$p_new}\n";
			fwrite($this->fp, $buffer, $c);
			$p_current += $c;
			$p_new += $c;
			fseek($this->fp, $p_current);
		}
		ftruncate($this->fp, $p_new);
		
		return self::OFFSET_SIZE;
	}
	
	/**
	* Pseudo blocking version of dequeue()
	* Returns immediately if data is available, otherwise polls/sleeps
	* every ConcurrentFIFO::$poll_frequency microseconds until data becomes available.
	*
	* @param int $timeout maximum time to block for or zero for forever
	* @return string data or null if timed out
	*/
	function bdequeue($timeout) {
		$start = microtime(true);
		
		while(true) {
			$data = $this->dequeue();
			if($data !== null) return $data;
			
			usleep($this->poll_frequency);
			if($timeout && (microtime(true) > $start + $timeout)) return null;
		}
	}
	
}

if(realpath($_SERVER['SCRIPT_FILENAME']) != __FILE__) return;
@unlink('data/test.dat');
$q = new ConcurrentFIFO('data/test.dat');

var_dump($q->is_empty());
$q->enqueue('test1');
$q->enqueue('test2');
var_dump($q->is_empty());
var_dump($q->dequeue());
var_dump($q->dequeue());
var_dump($q->dequeue());