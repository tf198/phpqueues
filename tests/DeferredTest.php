<?php
require_once dirname(__FILE__) . '/../Deferred.php';

class DeferredTest extends PHPUnit_Framework_TestCase {
	
	function setUp() {
		$this->stack = array();
	}
	
	function assertSequence($seq) {
		$this->assertSame(implode('->', $this->stack), $seq);
	}
	
	function testFired() {
		$d = new Deferred();
		$d->addCallback(array($this, 'cb_none'));
		
		$this->assertFalse($d->fired);
		$d->callback(1);
		$this->assertTrue($d->fired);
		
		$this->assertSequence('cb_none[1]');
		$this->assertSame($d->result, 1);
	}
	
	function testResults() {
		$d = new Deferred();
		$d->addCallback(array($this, 'cb_plus_1'));
		$d->addCallback(array($this, 'cb_none'));
		$d->addCallback(array($this, 'cb_times_2'));
		
		$d->callback(5);
		
		$this->assertSequence('cb_plus_1[5]->cb_none[6]->cb_times_2[6]');
		$this->assertSame($d->result, 12);
	}
	
	function testErrbacks() {
		$d = new Deferred();
		$d->addErrback(array($this, 'eb_none'));
		$d->addCallback(array($this, 'cb_err'));	// Y
		$d->addCallback(array($this, 'cb_none'));
		$d->addErrback(array($this, 'eb_none'));	// Y
		$d->addCallback(array($this, 'cb_none'));
		$d->addErrback(array($this, 'eb_err'));		// Y
		$d->addErrback(array($this, 'eb_none'));	// Y
		$d->addErrback(array($this, 'eb_okay'));	// Y
		$d->addErrback(array($this, 'eb_none'));
		$d->addCallback(array($this, 'cb_plus_1'));	// Y
		$d->addErrback(array($this, 'eb_none'));
		
		$d->callback(1);
		
		$this->assertSequence('cb_err[1]->eb_none[a]->eb_err[a]->eb_none[b]->eb_okay[b]->cb_plus_1[42]');
		$this->assertSame($d->result, 43);
	}
	
	function testAlreadyFired() {
		$d = new Deferred();
		$d->callback(1);
		
		$d->addCallback(array($this, 'cb_plus_1'));
		
		$this->assertSequence('cb_plus_1[1]');
		$this->assertSame($d->result, 2);
	}
	
	function testConstructor() {
		$d = new Deferred(1);
		
		$this->assertTrue($d->fired);
		$this->assertSame($d->result, 1);
		
		$d = new Deferred(new Exception('a'));
		$d->addErrback(array($this, 'eb_handled'));
		
		$this->assertTrue($d->fired);
		$this->assertSame($d->result, null);
	}
	
	function testFire() {
		$d = new Deferred();
		
		$d->callback(1);
		
		try {
			$d->callback(2);
			$this->fail("Should have thrown an exception");
	 	} catch(Exception $e) {
	 		$this->assertSame($e->getMessage(), 'Deferred already fired');
	 	}
	 	
	 	$this->assertSame($d->result, 1);
	}
	
	function testErrback() {
		$d = new Deferred();
		$d->addCallback(array($this, 'cb_none'));
		$d->addErrback(array($this, 'eb_handled'));
		
		$d->errback('c');
		
		$this->assertSequence('eb_handled[c]');
		$this->assertSame($d->result, null);
	}
	
	function testLateErrback() {
		$d = new Deferred(new Exception('Test exception'));
		$d->addErrback(array($this, 'eb_handled'));
		
		$this->assertSequence('eb_handled[Test exception]');
		$this->assertSame($d->result, null);
		
		unset($d);
	}
	
	function testBadCallback() {
		$d = new Deferred();
		try {
			$d->addCallback(array($this, 'false'));
			$d->fail("Should have thrown");
		} catch(UnexpectedValueException $e) {
		}
	}
	
	function testChaining() {
		$d = new Deferred();
		$d->addCallback(array($this, 'cb_none'))
			->addErrback(array($this, 'eb_none'))
			->addBoth(array($this, 'cb_none'))
			->addCallback(array($this, 'cb_plus_1'));
		$d->callback(3);
		
		$this->assertSequence('cb_none[3]->cb_none[3]->cb_plus_1[3]');
		$this->assertSame($d->result, 4);
	}
	
	function cb_none($r) {
		$this->stack[] = "cb_none[{$r}]";
		return $r;
	}
	
	function cb_plus_1($r) {
		$this->stack[] = "cb_plus_1[{$r}]";
		return $r + 1;
	}
	
	function cb_times_2($r) {
		$this->stack[] = "cb_times_2[{$r}]";
		return $r * 2;
	}
	
	function cb_err($r) {
		$this->stack[] = "cb_err[{$r}]";
		throw new Exception('a');
	}
	
	function eb_none($e) {
		$this->stack[] = "eb_none[{$e->getMessage()}]";
		return $e;
	}
	
	function eb_handled($e) {
		$this->stack[] = "eb_handled[{$e->getMessage()}]";
	}
	
	function eb_okay($e) {
		$this->stack[] = "eb_okay[{$e->getMessage()}]";
		return 42;
	}
	
	function eb_err($e) {
		$this->stack[] = "eb_err[{$e->getMessage()}]";
		throw new Exception('b');
	}
}