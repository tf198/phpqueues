# ConcurrentFIFO #

PHP file-based FIFO specifically designed for concurrent access - think IPC,
processing queues etc.  Can do over 18K/s operations depending
on disk speed with guaranteed atomicicity.  A single class with no library dependancies,
the only requirement is a file writable by all processes.

## Usage ##
Basic example is a user registration page that wants to send a welcome email to the new user.
```php
<?
require_once "ConcurrentFIFO.php";
$q = new ConcurrentFIFO('data/test.fifo');

$job = array('email' => 'bob@test.com', 'message' => 'Hello Bob');
$q->enqueue(json_encode($job)); // the queue only knows about strings
?>
```

You can then have a cron job that gets the jobs and processes them every X minutes:
```php
<?
require_once "ConcurrentFIFO.php";
$q = new ConcurrentFIFO('data/test.fifo');

while($data = $q->dequeue()) {
	$job = json_decode($data, true); // as array instead of object
	mail($job['email'], "Your registration", $job['message']);
}
```

or daemonise the process - with this you can run as many 'workers' required and the emails
will be sent as soon as possible:
```php
<?
require_once "ConcurrentFIFO.php";
$q = new ConcurrentFIFO('data/test.fifo');

while(true) {
	$data = $q->bdequeue(0); // blocks until an item is available
	$job = json_decode($data, true);
	mail($job['email'], "Your registration", $job['message']);
}
```

## Implementation ##

Data is written sequencially to the file and the first item pointer is advanced
as items are dequeued.  The file is truncated when all items have been dequeued and
compacted when the the wasted space is greater than 4K so the file should remain a
reasonable size as long as the number of dequeues > enqueues over time.

## Performance ##

### bench.php ###
The first version was able to get > 35K ops/s enqueue() and 20K ops/s dequeue() but without any fault tolerance.  The new version
is more robust and manages around 19K ops/s for all operations which I think is a fair compromise (and on a par with pipelined Redis commands).
These figures are obviously with a single reader/writer so multiple accessors will share this 'bandwidth' plus a bit of overhead for obtaining the
locks.  19K ops/sec was recorded on my AMD Phenom II x6 3.20Ghz with a 7200 RPM Barracuda SATA drive and a queue size of 10,000 items - it reduces to
around 14K ops/sec with a queue size of 100,000 items but the queue is intended to be kept sparse anyway - if you get up to these sort of numbers
you dont have enough worker processes!

### examples/load_test.php ###
This starts multiple producer (job_creator.php) and consumer (job_worker.php) processes and allows you to watch the number of tasks processed in
a separate console with job_manager.php.  I have run this up to 25 producers and 100 consumers on a single system with no deadlocks or process starvation
though the setup is fairly controlled and this could do with some more rigorous testing under heavy loads. 

### TODO ###
* The data structure should be able to recover from crashes during enqueue()/dequeue() but the methods haven't been implemented yet.
* Provide a lightweight Redis wrapper so this can be used for prototyping.
* Should be simple to add a basic REST frontend so one machine can act as a messaging server for multiple systems
