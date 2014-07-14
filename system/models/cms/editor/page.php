<form action="" method="post" class="bootstrap-frm">
	<input type="hidden" name='csrf' value='<?php echo $csrf ?>'>
	<input type="hidden" name='raster_action' value='save_page'>
	<input type="hidden" name='page_name' value='<?php echo $type ?>'>
	<input type="hidden" name='variable_name' value='<?php echo util::post('name') ?>'>
	<h1>Edit <?php echo ucfirst(str_replace('_', ' ', util::post('name'))) ?>
	<span>Please change the data in the box below</span></h1>
	<label>
		<textarea name="raster_page_value"><?php echo $value ?></textarea>
	</label>
	<label>
		<span>&nbsp;</span>
		<input type="submit" class="button" value="Save">
	</label>
</form>