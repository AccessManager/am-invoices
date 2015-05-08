<?php

function pr($array)
{
	echo "<pre>";
	print_r($array);
	echo "</pre>";
	exit;
}

function isValidDate($date)
{
	return $date == '0000-00-00 00:00:00' || $date == NULL ? FALSE : TRUE;
}