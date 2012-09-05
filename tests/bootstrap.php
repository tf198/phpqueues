<?php
require_once dirname(__FILE__) . '/../ConcurrentFIFO.php';

if(!is_dir('data')) {
	mkdir('data') or die('Failed to create data dir');
}