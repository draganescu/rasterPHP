<?php
class pagination
{
	
	var $current_page_template = "";
	var $page_template = "";
	var $current_page_no;
	var $prev_page_no;
	var $next_page_no;
	
	function paginate($modelMethod)
	{
		$app = the::app();
		list($model, $method) = explode(".", $modelMethod);
		if(!class_exists($model))
			$app->dependency($model);
		$data = $app->factory($model)->$method(true) and extract($data);
		
		if(($pages = $total/$perpage) < 1) return "";
				
		$this->current_page_no = $current;
		$current + 1 > $pages ? $this->next_page_no = $pages : $this->next_page_no = $current + 1;
		$current - 1 < 1 ? $this->prev_page_no = 1 : $this->prev_page_no = $current - 1;
		
		$pagination = "";
		for($i=1; $i<=$pages; $i++)
			if($i==$current)
				$pagination .= $this->render_current($i);
			else
				$pagination .= $this->render_page($i);
		
		
		$ret = array(array(
			"next_page"=>$this->next_page_no,
			"prev_page"=>$this->prev_page_no,
			"pages" => $pagination
			));
		return $ret;
	}
	
	function render_current($pageno)
	{
		
		if($this->current_page_template == "") return "";
		
		return preg_replace(
					"%<!-- print.page -->(.*?)<!-- /print.page -->%",
					$pageno,
					$this->current_page_template
			   );
	}
	
	function render_page($pageno)
	{
		if($this->page_template == "") return "";
		return preg_replace(
					"%<!-- print.page -->(.*?)<!-- /print.page -->%",
					$pageno,
					$this->page_template
			   );
	}
	
	function page_template()
	{
		$app = the::app();
		$this->page_template = $app->current_block;
		return "<!-- template set -->";
	}
		
	function current_page_template()
	{
		$app = the::app();
		$this->current_page_template = $app->current_block;
		return "<!-- template set -->";
	}
	
}