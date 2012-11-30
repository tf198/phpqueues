<?php
require_once "bootstrap.php";

require_once dirname(__FILE__) . '/../RedisQueues.php';

class RedisTest extends PHPUnit_Framework_TestCase {
	
	private $redis;
	
	private $lists = array('list1', 'list2');
	
	function setUp() {
		foreach($this->lists as $list) {
			$datafile = "data/{$list}.fifo";
			if(file_exists($datafile)) unlink($datafile) or die("Failed to remove {$datafile}");
		}
		$this->redis = new RedisQueues('data');
	}
	
	function tearDown() {
		$this->redis = null;
	}
	
	function testUsage() {
		$this->assertSame(1, $this->redis->lpush('list1', 'test1'));
		$this->assertSame(2, $this->redis->lpush('list1', 'test2'));
		
		$this->assertSame(2, $this->redis->llen('list1'));
		$this->assertSame(0, $this->redis->llen('list2'));
		
		$this->assertSame('test1', $this->redis->rpop('list1'));
		$this->assertSame('test2', $this->redis->rpoplpush('list1', 'list2'));
		$this->assertSame('test2', $this->redis->rpop('list2'));
	}
	
}