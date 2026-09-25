<?php $label = ucfirst(str_replace('_', ' ', (string)util::post('name'))); ?>
<form action="" method="post" class="bootstrap-frm">
	<input type="hidden" name="csrf" value="<?php echo util::e($csrf) ?>">
	<input type="hidden" name="raster_action" value="save_page">
	<input type="hidden" name="page_name" value="<?php echo util::e($type) ?>">
	<input type="hidden" name="variable_name" value="<?php echo util::e(util::post('name')) ?>">
	<h1>Edit <?php echo util::e($label) ?>
	<span>Please change the data in the box below</span></h1>
	<label>
		<textarea name="raster_page_value"><?php echo util::e($value) ?></textarea>
	</label>
	<label>
		<span>&nbsp;</span>
		<input type="submit" class="button" value="Save">
	</label>
</form>
