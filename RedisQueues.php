<?php
require_once "ConcurrentFIFO.php";

/**
 * Lightweight wrapper to emulate Redis behaviour using ConcurrentFIFO objects.
 * Can be used to prototype for high IO systems.
 * 
 * Currently implemented:
 * LPUSH LPUSHX RPOP BRPOP RPOPLPUSH LLEN DEL EXISTS
 */
class RedisQueues {
	
	private $dir;
	private $mutex;
	private $queues = array();
	
	function __construct($dir) {
		if(!is_dir($dir)) {
			mkdir($dir);
		}
		$this->dir = $dir;
		$this->mutex = fopen("{$this->dir}/redis.lock", 'w');
		$this->mutex or die("Failed to open mutex");
	}
	
	function _get_filename($list) {
		return sprintf("%s/%s.fifo", $this->dir, $list);
	}
	
	function _get_fifo($list, $create=true) {
		if(!isset($this->queues[$list])) {
			$datafile = $this->_get_filename($list);
			if(!file_exists($datafile) && !$create) return null;
			$this->queues[$list] = new ConcurrentFIFO($datafile);
		}
		return $this->queues[$list];
	}
	
	function exists($key) {
		$list = $this->_get_fifo($key, false);
		return ($list != null);
	}
	
	function del($key) {
		$list = $this->_get_fifo($key);
		$list->clear();
	}
	
	function llen($key) {
		$list = $this->_get_fifo($key, false);
		return ($list == null) ? 0 : $list->count();
	}
	
	function lpush($key, $value) {
		$list = $this->_get_fifo($key);
		return $list->enqueue($value);
	}
	
	function rpop($key) {
		$list = $this->_get_fifo($key);
		return $list->dequeue();
	}
	
	function brpop($key, $timeout) {
		$list = $this->_get_fifo($key);
		return $list->bdequeue($timeout);
	}
	
	function rpoplpush($source, $dest) {
		flock($this->mutex, LOCK_EX) or die("Failed to get lock");
		$data = $this->rpop($source);
		if($data) $this->lpush($dest, $data);
		flock($this->mutex, LOCK_UN);
		return $data;
	}
	
	function lpushx($key, $value) {
		$list = $this->_get_fifo($key, false);
		if($list == null) return 0;
		return $list->enqueue($value);
	}
}
