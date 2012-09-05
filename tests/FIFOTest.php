<?php
require_once dirname(__FILE__) . '/../ConcurrentFIFO.php';

if(!is_dir('data')) {
	mkdir('data') or die('Failed to create data dir');
}

class FIFOTest extends PHPUnit_Framework_TestCase {
	
	const DATAFILE = 'data/test.fifo';
	
	private $fifo;
	
	function setUp() {
		if(file_exists(self::DATAFILE)) unlink(self::DATAFILE);
		$this->fifo = new ConcurrentFIFO(self::DATAFILE);
	}
	
	function tearDown() {
		$this->fifo = null;
	}
	
	function testEnqueueDequeue() {
		for($i=0; $i<10; $i++) {
			$this->assertSame($i+1, $this->fifo->enqueue(str_repeat('a', $i+1)));
		}
		
		$this->assertFalse($this->fifo->is_empty());
		
		for($i=0; $i<10; $i++) {
			$this->assertSame(str_repeat('a', $i+1), $this->fifo->dequeue());
		}
		
		$this->assertTrue($this->fifo->is_empty());
		
		$this->assertSame(null, $this->fifo->dequeue());
	}
	
	function testCount() {
		for($i=0; $i<10; $i++) {
			$this->fifo->enqueue(str_repeat('a', $i+1));
			$this->assertSame($i+1, $this->fifo->count());
		}
	}
	
	function testRandomAccess() {
		$seq = array(1,0,1,1,0,1,0,1,1,0,0,1,0,1,0);
		
		$queue = array();
		
		foreach($seq as $key=>$op) {
			if($op) {
				$data = str_repeat('s', $key+1);
				array_push($queue, $data);
				$this->assertSame(count($queue), $this->fifo->enqueue($data));
			} else {
				$data = $this->fifo->dequeue();
				$this->assertSame(array_shift($queue), $data);
			}
		}
		
		$this->assertSame(count($queue), $this->fifo->count());
	}
	
	function testCompact() {
		$this->fifo->enqueue('a');
		$this->fifo->enqueue('aa');
		$this->fifo->enqueue('aaa');
		
		$this->fifo->dequeue();
		$this->fifo->dequeue();
		
		$index = $this->fifo->_read_index();
		
		$this->fifo->_compact($index['start'], $index['end'], $index['len']);
		
		$this->assertSame('aaa', $this->fifo->dequeue());
	}
	
	function testAutoCompaction() {
		$data = str_repeat('a', 98); // 100 bytes per record 
		for($i=0; $i<100; $i++) $this->fifo->enqueue($data);
		
		// with 4K compaction then 40 records are read before compaction
		for($i=0; $i<40; $i++) $this->fifo->dequeue();
		
		$this->assertEquals(array('start' => 4016, 'end' => 10016, 'len' => 60, 'checksum' => 10412), $this->fifo->_read_index());
		
		// one more dequeue should trigger compaction
		$this->fifo->dequeue();
		$this->assertEquals(array('start' => 16, 'end' => 5916, 'len' => 59, 'checksum' => 5943), $this->fifo->_read_index());
		
		// check that the pointers are still correct for appending
		$this->fifo->enqueue('Hello World');
		
		// remove the rest of the auto elements (there will be another compaction during this)
		for($i=0; $i<59; $i++) $this->fifo->dequeue();
		
		$this->assertSame('Hello World', $this->fifo->dequeue());
		$this->assertEquals(null, $this->fifo->_read_index());
	}
	
}