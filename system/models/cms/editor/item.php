<?php $name = (string)util::post('name'); ?>
<form action="" method="post" class="bootstrap-frm">
	<input type="hidden" name="csrf" value="<?php echo util::e($csrf) ?>">
	<input type="hidden" name="raster_action" value="save_data">
	<input type="hidden" name="data_id" value="<?php echo (int)util::post('did') ?>">
	<input type="hidden" name="data_name" value="<?php echo util::e($name) ?>">
	<h1>Edit <?php echo util::e(ucfirst(str_replace('_', ' ', $name))) ?> item
	<span>Please change the data in the fields below</span></h1>
	<?php foreach ($fields as $key => $value): ?>
	<?php if (in_array($key, array('id', 'enabled', 'updated_at', 'password'))) continue; ?>
	<label>
		<h4><?php echo util::e(ucfirst(str_replace('_', ' ', $key))) ?></h4>
		<?php if (preg_match("/media_([0-9]+)_([0-9]+)/", $key, $media_object)): ?>
		<small>Click image to update it</small>
		<div id="<?php echo util::e($key) ?>" style="position:relative; width: <?php echo (int)$media_object[1] ?>px; height: <?php echo (int)$media_object[2] ?>px; box-sizing: content-box; border-radius: 2px; background-image: url('<?php echo util::e($data->$key) ?>'); background-repeat: no-repeat; background-position: center; box-shadow: 8px 8px 0px rgba(0,0,0,0.1);" class="media_object"></div>
		<input type="hidden" id="input_<?php echo util::e($key) ?>" name="<?php echo util::e($key) ?>" value="<?php echo util::e($data->$key) ?>" />
		<?php elseif (strpos((string)$value, 'text') !== false || strlen((string)$data->$key) > 80): ?>
		<textarea name="<?php echo util::e($key) ?>"><?php echo util::e($data->$key) ?></textarea>
		<?php else: ?>
		<input type="text" name="<?php echo util::e($key) ?>" value="<?php echo util::e($data->$key) ?>">
		<?php endif; ?>
	</label>	
	<?php endforeach ?>
	<label>
		<span>&nbsp;</span>
		<input type="submit" class="button" value="Save">
	</label>
</form>
