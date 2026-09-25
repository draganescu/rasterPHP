<?php $name = (string)util::post('name'); ?>
<form action="" method="post" class="bootstrap-frm">
	<input type="hidden" name="csrf" value="<?php echo util::e($csrf) ?>">
	<input type="hidden" name="raster_action" value="add_data">
	<input type="hidden" name="data_name" value="<?php echo util::e($name) ?>">
	<h1>Add a(n) <?php echo util::e(ucfirst(str_replace('_', ' ', $name))) ?> item
	<span>Please add the data in the fields below</span></h1>
	<?php foreach ($fields as $key => $value): ?>
	<?php if (in_array($key, array('id', 'enabled', 'updated_at', 'password'))) continue; ?>
	<label>
		<h4><?php echo util::e(ucfirst(str_replace('_', ' ', $key))) ?></h4>
		<?php if (strpos((string)$value, 'text') !== false): ?>
		<textarea name="<?php echo util::e($key) ?>"></textarea>
		<?php else: ?>
		<input type="text" name="<?php echo util::e($key) ?>" value="">
		<?php endif; ?>
	</label>	
	<?php endforeach ?>
	<label>
		<span>&nbsp;</span>
		<input type="submit" class="button" value="Save">
	</label>
</form>
