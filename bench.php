<?php

define('LOOP', 5000);
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
