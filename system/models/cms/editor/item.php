<form action="" method="post" class="bootstrap-frm">
	<input type="hidden" name='csrf' value='<?php echo $csrf ?>'>
	<input type="hidden" name='raster_action' value='save_data'>
	<input type="hidden" name='data_id' value='<?php echo util::post('did') ?>'>
	<input type="hidden" name='data_name' value='<?php echo util::post('name') ?>'>
	<h1>Edit <?php echo ucfirst(str_replace('_', ' ', util::post('name'))) ?> item
	<span>Please change the data in the fields below</span></h1>
	<?php foreach ($fields as $key => $value): ?>
	<?php 
		if ($key == 'id') {
			continue;
		}
	?>
	<label>
		<h4>
			<?php echo ucfirst(str_replace('_', ' ', $key)) ?>
		</h4>
		<?php if ($value == 'text'): ?>
		<textarea name="<?php echo $key ?>">
<?php echo $data->$key ?>
		</textarea>
		<?php else: ?>
		<input type="text" name='<?php echo $key ?>' value='<?php echo $data->$key ?>'>
		<?php endif; ?>
	</label>	
	<?php endforeach ?>
	
	<label>
		<span>&nbsp;</span>
		<input type="submit" class="button" value="Save">
	</label>
</form>