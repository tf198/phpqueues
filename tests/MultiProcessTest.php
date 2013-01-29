<?php
require_once dirname(__FILE__) . '/../MultiProcess.php';
require_once "mp_func.php";

class DeferredTest extends PHPUnit_Framework_TestCase {
	
	function testUsage() {
		# do everything as one big test to avoid too many processes being created
		$mp = new MultiProcessManager(dirname(__FILE__) . '/mp_func.php');
		
		$l = array();
		
		touch(MP_EXIT);
		$this->assertTrue(file_exists(MP_EXIT));
		
		$l[] = $mp->defer('mp_hi', 'Andy');
		$l[] = $mp->defer('mp_hi', 'Bob');
		$l[] = $mp->defer('mp_exit'); // fakes a process dying
		$l[] = $mp->defer('mp_hi', 'Charlie');
		$this->failure = $mp->defer('mp_err', 'Dave'); // throws an exception
		$l[] = $this->failure;
		
		$d = new DeferredList($l);
		$d->addCallback(array($this, 'check_results'));
		
		$mp->process();
	}
	
	function testCantFind() {
		try {
			$mp = new MultiProcessManager('nosuchfile.php');
			$this->fail();
		} catch(Exception $e) {
			$this->assertSame($e->getMessage(), "Cannot locate 'nosuchfile.php'");
		}
	}
	
	function check_results($results) {
		$this->assertSame(array_shift($results), array(true, "Hello Andy"));
		$this->assertSame(array_shift($results), array(true, "Hello Bob"));
		$this->assertSame(array_shift($results), array(true, "Exit okay"));
		$this->assertSame(array_shift($results), array(true, "Hello Charlie"));
		
		list($ok, $e) = array_shift($results);
		$this->assertSame(false, $ok);
		$this->assertSame($e->getMessage(), 'Test Exception');
		$this->failure->addErrback('pi');
		
		$this->assertEmpty($results);
	}
}