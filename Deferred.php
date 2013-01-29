<?php
/**
* A blatent ripoff of twisted Deferred.
*/
class Deferred {
	private $stack = array();
	public $fired = false;
	public $result = null;
	
	/**
	 * Create a new deferred.
	 * 
	 * If the $result parameter is passed then the deferred is considered to have
	 * already been fired, though callbacks/errbacks can still be added.
	 * 
	 * @param string $result Optional starting result
	 */
	function __construct($result=null) {
		if($result !== null) {
			$this->result = $result;
			$this->_fire();
		}
	}
	
	function __destruct() {
		if($this->result instanceof Exception) {
			trigger_error("Deferred exception not handled: " . $this->result->getMessage());
		}
	}
	
	/**
	 * Add a pair of callback/errbacks.
	 */
	function addCallbacks($callback, $errback, $callback_params=null, $errback_params=null) {
		$this->stack[] = array($callback, $errback, $callback_params, $errback_params);
		if($this->fired) $this->_fire();
		return $this;
	}
	
	function addCallback($callback) {
		if(!is_callable($callback)) throw new UnexpectedValueException("Callable required");
		$params = array_slice(func_get_args(), 1);
		return $this->addCallbacks($callback, null, $params);
	}
	
	function addErrback($errback) {
		if(!is_callable($errback)) throw new UnexpectedValueException("Callable required");
		$params = array_slice(func_get_args(), 1);
		return $this->addCallbacks(null, $errback, null, $params);
	}
	
	function addBoth($callback) {
		if(!is_callable($callback)) throw new UnexpectedValueException("Callable required");
		$params = array_slice(func_get_args(), 1);
		return $this->addCallbacks($callback, $callback, $params, $params);
	}
	
	function callback($result) {
		if($this->fired) throw new Exception("Deferred already fired");
		$this->result = $result;
		$this->_fire();
	}
	
	function errback($e) {
		if(!$e instanceof Exception) $e = new Exception($e);
		$this->callback($e);
	}
	
	function succeeded() {
		if(!$this->fired) throw new Exception("Deferred not fired");
		return (! $this->result instanceof Exception);
	}
	
	private function _fire() {
		$this->fired = true;
		while($spec = array_shift($this->stack)) {
			// select callback or errback
			if($this->result instanceof Exception) {
				$callable = $spec[1];
				$params = $spec[3];
			} else {
				$callable = $spec[0];
				$params = $spec[2];
			}
			
			// drop through to next if nothing set
			if($callable === null) continue;
			
			// execute and catch any exceptions
			array_unshift($params, $this->result);
			try {
				$this->result = call_user_func_array($callable, $params);
			} catch(Exception $e) {
				$this->result = $e;
			}
		}
	}
}

class DeferredList extends Deferred {
	
	private $waiting = 0;
	private $deferred_list = null;
	
	function __construct($l) {
		# setup counters
		$this->deferred_list = $l;
		$this->waiting = count($l);
		
		# add a callback for each deferred that fires regardless
		foreach($l as $d) $d->addBoth(array($this, '_finished'));
	}
	
	function _finished($result) {
		$this->waiting--;
		
		if($this->waiting == 0) {
			$results = array();
			foreach($this->deferred_list as $d) $results[] = array($d->succeeded(), $d->result);
			$this->callback($results); 
		}
		
		// pass through the result
		return $result;
	}
	
	
}