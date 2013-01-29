<?php
define("MP_EXIT", 'exit.lock');

function mp_one() { return 1; }

function mp_hi($name) {	return "Hello {$name}"; }

function mp_err() { throw new Exception("Test Exception"); }

function mp_exit() {
	if( file_exists(MP_EXIT)) {
		unlink(MP_EXIT);
		exit(1);
	}
	return "Exit okay";
}