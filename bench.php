<?php

define('LOOP', 100000);
define('TEST_FIFO', 'test.fifo');

include "ConcurrentFIFO.php";

@unlink(TEST_FIFO);
$q = new ConcurrentFIFO(TEST_FIFO);

$start = microtime(true);
for($i=0; $i<LOOP; $i++) {
	$q->enqueue('test_' . $i);
}
$ms = microtime(true) - $start;
printf("%30s %4d ms [%6d ops/s]\n", 'ENQUEUE', $ms * 1000, LOOP / $ms); 

$start = microtime(true);
for($i=0; $i<LOOP; $i++) {
	assert($q->dequeue() == 'test_' . $i);
}
$ms = microtime(true) - $start;
printf("%30s %4d ms [%6d ops/s]\n", 'DEQUEUE', $ms*1000, LOOP / $ms); 
exit;
for($i=0; $i<LOOP; $i++) $q->append('test_' . $i);
$start = microtime(true);
for($i=LOOP-1; $i>=0; $i--) {
	assert($q->pop() == 'test_' . $i);
}
$ms = microtime(true) - $start;
printf("%30s %4d ms [%6d ops/s]\n", 'POP', $ms*1000, LOOP / $ms); 
