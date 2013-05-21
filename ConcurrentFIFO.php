<?php
/**
 * File-based FIFO specifically designed for concurrent access - think IPC,
 * processing queues etc.  Can do over 18K/s operations depending
 * on disk speed with guaranteed atomicicity.
 *
 * Data is written sequencially to the file and the first item pointer is advanced
 * as items are dequeued.  The file is truncated when all items have been dequeued and
 * compacted when the the wasted space is greater than 4K so the file should remain a
 * reasonable size as long as the number of dequeues > enqueues over time.
 */
class ConcurrentFIFO {

	/**
	 * Our file pointer
	 * @var resource
	 */
	private $fp, $filename;

	const INDEX_FORMAT = 'V4';
	const INDEX_UNPACK = 'Vstart/Vend/Vlen/Vchecksum';
	const INDEX_SIZE = 16;

	const LENGTH_FORMAT = 'v';
	const LENGTH_SIZE = 2;

	const BUFSIZ = 8192; // copy 8K chunks when compacting
	const COMPACT_AT = 4096; // allow 4K before truncating

	/**
	 * Poll frequency for blocking operations (in microseconds)
	 * @var int
	 */
	public $poll_frequency = 100000;

	/**
	 * Open or create a new list
	 * 
	 * @param string $filename path to file
	 * @throws Exception if the open fails for any reason
	 */
	function __construct($filename) {
		$this->fp = fopen($filename, 'cb+');
		$this->filename = $filename;
		if(!$this->fp) throw new Exception("Failed to open '{$filename}'");
	}

	/**
	 * Read the list index and validate the checksum.
	 * Returns an assoc array with the elements 'start', 'end', 'len' and 'checksum'
	 *
	 * @access private
	 * @return multitype:int 
	 */
	function _read_index() {
		fseek($this->fp, 0);
		$buffer = fread($this->fp, self::INDEX_SIZE);
		if(!$buffer) return null;
		$data = unpack(self::INDEX_UNPACK, $buffer);
		if(($data['start'] ^ $data['end'] ^ $data['len']) != $data['checksum']) throw new Exception("Index corrupt - rebuild required");
		return $data;
	}

	/**
	 * Writes the list index including a checksum.
	 * 
	 * @access private
	 * @param int $start first item pointer
	 * @param int $end end of data pointer
	 * @param int $len number of items
	 */
	function _write_index($start, $end, $len) {
		$checksum = $start ^ $end ^ $len;
		fseek($this->fp, 0);
		fwrite($this->fp, pack(self::INDEX_FORMAT, $start, $end, $len, $checksum));
	}

	/**
	 * Read an integer from the datafile according to the format provided.
	 * 
	 * @access private
	 * @param string $format one of the unpack() format strings
	 * @param int $size number of bytes the format string requires
	 * @return int
	 */
	function _read_int($format, $size) {
		$buffer = fread($this->fp, $size);
		if(!$buffer) return 0;
		$u = unpack($format, $buffer);
		return $u[1];
	}

	/**
	 * Get the first available from the queue, or null if the queue is empty
	 * 
	 * @return string
	 */
	function dequeue() {
		// need an exclusive lock
		flock($this->fp, LOCK_EX) or die('Failed to get lock');
		$index = $this->_read_index();

		if($index) {
			// read the length and get the data
			fseek($this->fp, $index['start']);
			$l = $this->_read_int(self::LENGTH_FORMAT, self::LENGTH_SIZE);
			// TODO: should be able to recover from zero data length here
			if(!$l) throw new Exception("Zero data length");
			$data = fread($this->fp, $l);
				
			$p = ftell($this->fp);
			// check if there is any more data
			if($p < $index['end']) {
				if($p > self::COMPACT_AT) {
					// compaction writes the new index
					$this->_compact($p, $index['end'], $index['len'] - 1);
				} else {
					// just update the start pointer
					$this->_write_index($p, $index['end'], $index['len'] - 1);
				}
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

	/**
	 * Add an item to the queue.
	 * 
	 * @param unknown_type $data
	 * @throws Exception if there is no data to add
	 * @return int the number of items in the queue after this operation
	 */
	function enqueue($data) {
		$data = (string) $data;
		$c = strlen($data);

		if($c == 0) throw new Exception("No data");
		
		// get exclusive lock
		flock($this->fp, LOCK_EX) or die('Failed to get lock');

		// read and update the index
		$index = $this->_read_index();

		if(!$index) {
			$index = array('start' => self::INDEX_SIZE, 'end' => self::INDEX_SIZE, 'len' => 0);
		}

		$this->_write_index($index['start'], $index['end'] + $c + self::LENGTH_SIZE, $index['len'] + 1);
		fseek($this->fp, $index['end']);

		// write length followed by data
		fwrite($this->fp, pack(self::LENGTH_FORMAT, $c), self::LENGTH_SIZE);
		fwrite($this->fp, $data, $c);

		//echo "Wrote {$data} at {$index['end']}\n";
		// release lock
		flock($this->fp, LOCK_UN);
		return $index['len'] + 1;
	}

	/**
	 * Check if the queue is empty.
	 * Note that calling dequeue() and checking for null is an atomic operation where as
	 * if (!$q->is_empty()) $data = $q->dequeue()
	 * is not!
	 * @return boolean
	 */
	function is_empty() {
		flock($this->fp, LOCK_SH) or die('Failed to get lock');
		$index = $this->_read_index();
		flock($this->fp, LOCK_UN);
		return ($index === null);
	}
	
	/**
	 * Get the number of items currently in the queue
	 * 
	 * @return int
	 */
	function count() {
		flock($this->fp, LOCK_SH) or die('Failed to get lock');
		$index = $this->_read_index();
		flock($this->fp, LOCK_UN);
		return ($index === null) ? 0 : $index['len'];
	}

	/**
	 * Remove all elements from the queue.  
	 * Actually just truncates the file.
	 */
	function clear() {
		flock($this->fp, LOCK_EX) or die('Failed to get lock');
		ftruncate($this->fp, 0);
		flock($this->fp, LOCK_UN);
	}
	
	/**
	 * Delete the queue entirely.
	 * Note any further attempts to modify the queue will result in an exception.
	 */
	function delete() {
		flock($this->fp, LOCK_EX) or die('Failed to get lock');
		fclose($this->fp);
		unlink($this->filename);
		$this->fp = null;
	}
	
	/**
	 * Return an array of items from the queue. Does not modify the queue in any way.
	 * 
	 * @param int $offset skip $offset items at the start
	 * @param int $count return up to $count items
	 * @return multitype:string
	 */
	function items($offset=0, $count=0) {
		flock($this->fp, LOCK_SH) or die('Failed to get lock');
		
		$index = $this->_read_index();
		if(!$index) return array();
		
		$result = array();
		$p = $index['start'];
		while($p < $index['end']) {
			$l = $this->_read_int(self::LENGTH_FORMAT, self::LENGTH_SIZE);
			if(!$l) break;
			
			$data = fread($this->fp, $l);
			$p += $l + self::LENGTH_SIZE;
			assert($p == ftell($this->fp));
			
			if($offset) {
				$offset--;
			} else {
				$result[] = $data;
				if($count) {
					$count--;
					if(!$count) break;
				}
			}
		}
		
		flock($this->fp, LOCK_UN);
		return $result;
	}

	/**
	 * Compacts the data file by shifting the unprocessed items to the start of the datafile
	 * and truncating to the new length.  This is currently an unprotected operation so
	 * if this crashes mid operation the the data will corrupt.  The parameters are the current
	 * values from the index.
	 * 
	 * @access private
	 * @todo protect this operation
	 * @param int $start current start pointer
	 * @param int $end current end pointer
	 * @param int $len number of items
	 */
	function _compact($start, $end, $len) {
		// truncate start
		$p_current = $start;
		$p_new = self::INDEX_SIZE;

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

		$this->_write_index(self::INDEX_SIZE, $end - $start + self::INDEX_SIZE, $len);
	}

	/**
	 * Pseudo blocking version of dequeue()
	 * Returns immediately if data is available, otherwise polls/sleeps
	 * every ConcurrentFIFO::$poll_frequency microseconds until data becomes available.
	 *
	 * @param int $timeout maximum time (in seconds) to block for, or zero for forever
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
	
	function __toString() {
		return "<FIFO: {$this->filename}>";
	}

}