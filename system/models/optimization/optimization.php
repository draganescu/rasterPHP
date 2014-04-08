<?php
class optimization
{
	var $start = array();
	var $end = array();
	var $stats = array();
	var $indices = '';
	
	function start($event)
	{
		$app = the::app();
		$this->start[$event] = $this->microtime_float();
		return true;
	}
	
	function end($event)
	{
		$app = the::app();
		$this->end[$event] = $this->microtime_float();
		return true;
	}
	
	function setup()
	{
		$app = the::app();
		
		if(file_exists(BASE."../models/optimization/stats.php") === false)
			$app->observe('clean_log_file', 'optimization','no_stats');
		
		require_once(BASE."../models/optimization/stats.php");
		if(!is_array($stats)) $app->observe('clean_log_file', 'optimization','no_stats');
		
		$this->stats = $stats;
		
		foreach($stats as $key=>$stat)
		{
			$app->observe($stat[0], 'optimization', 'start');
			$app->observe($stat[1], 'optimization', 'end');			
			$this->indices .= "MEASURE: ".ucfirst(str_replace("_"," ",$key))."<br/>\n";
		}
		$app->observe('clean_log_file', 'optimization','the_indices');
		$app->observe('after_output','optimization','log_profile');
		
		return true;
	}
	
	function log_profile()
	{
		$app = the::app();
		$results = '<br/><br/>';
		
		foreach($this->stats as $stat=>$events)
		{
			$diff = ($this->end[$events[1]] - $this->start[$events[0]]);
			$results .= ucfirst(str_replace("_"," ",$stat))." executed in ".$diff." seconds<br/>\n";
		}
		
		$app->log($results);
		return true;
	}
	
	function microtime_float()
	{
	    list($usec, $sec) = explode(" ", microtime());
	    return ((float)$usec + (float)$sec);
	}
	
	function no_stats()
	{
		$app = the::app();
		$app->log("What to measure? There is no stats.php file in the optimization directory or it is malformed.");
		return true;
	}

	function the_indices()
	{
		$app = the::app();
		$app->log($this->indices);
		return true;
	}
	
}