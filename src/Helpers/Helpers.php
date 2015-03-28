<?php

if( ! function_exists('pr') ) {
	function pr($array)
	{
		echo "<pre>";
		print_r($array);
		echo "</pre>";
		exit;
	}	
}


function isValidDate($date)
{
	return $date == '0000-00-00 00:00:00' || $date == NULL ? FALSE : TRUE;
}

function calculateCostPerDay( $price )
{
	$cost_per_day = $price / 30;
	return number_format( (float) $cost_per_day,2,'.','');

}