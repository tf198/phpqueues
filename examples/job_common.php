<?php
define('JOBS_BASE', dirname(dirname(__FILE__)));

require_once JOBS_BASE . '/ConcurrentFIFO.php';

define('JOBS_IN', JOBS_BASE . '/data/jobs_in.fifo');
define('JOBS_OUT', JOBS_BASE . '/data/jobs_out.fifo');

define('JOBS_LOCK', JOBS_BASE . '/data/jobs.running');
touch(JOBS_LOCK);