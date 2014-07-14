<form action="" method="post" class="bootstrap-frm">
	<input type="hidden" name='csrf' value='<?php echo $csrf ?>'>
	<input type="hidden" name='raster_action' value='add_data'>
	<input type="hidden" name='data_name' value='<?php echo util::post('name') ?>'>
	<h1>Add a(n) <?php echo ucfirst(str_replace('_', ' ', util::post('name'))) ?> item
	<span>Please add the data in the fields below</span></h1>
	<?php foreach ($fields as $key => $value): ?>
	<?php 
		if ($key == 'id' || $key == 'enabled' || $key == 'updated_at') {
			continue;
		}
	?>
	<label>
		<h4>
			<?php echo ucfirst(str_replace('_', ' ', $key)) ?>
		</h4>
		<?php if ($value == 'text'): ?>
		<textarea name="<?php echo $key ?>"></textarea>
		<?php else: ?>
		<input type="text" name='<?php echo $key ?>' value=''>
		<?php endif; ?>
	</label>	
	<?php endforeach ?>
	
	<label>
		<span>&nbsp;</span>
		<input type="submit" class="button" value="Save">
	</label>
</form>