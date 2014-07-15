<form action="" method="post" class="bootstrap-frm">
	<input type="hidden" name='csrf' value='<?php echo $csrf ?>'>
	<input type="hidden" name='raster_action' value='save_data'>
	<input type="hidden" name='data_id' value='<?php echo util::post('did') ?>'>
	<input type="hidden" name='data_name' value='<?php echo util::post('name') ?>'>
	<h1>Edit <?php echo ucfirst(str_replace('_', ' ', util::post('name'))) ?> item
	<span>Please change the data in the fields below</span></h1>
	<?php foreach ($fields as $key => $value): ?>
	<?php 
		if ($key == 'id' || $key == 'enabled' || $key == 'updated_at') {
			continue;
		}
	?>
	<label>
		<h4>
			<?php echo ucfirst(str_replace('_', ' ', $key)) ?>
			<br>
			<small>Click image to update it</small>
		</h4>
		<?php if (preg_match("/media_([0-9]+)_([0-9]+)/", $key, $media_object)): ?>
		<div id="<?php echo $key ?>" style="position:relative; width: <?php echo $media_object[1] ?>px; height: <?php echo $media_object[2] ?>px; box-sizing: content-box; -moz-box-sizing: content-box; border-radius: 2px; background-image: url(<?php echo $data->$key ?>); background-repeat: no-repeat; background-position: center; box-shadow: 8px 8px 0px rgba(0,0,0,0.1);" class='media_object'>
		</div>
		<input type="hidden" id='input_<?php echo $key ?>' name='<?php echo $key ?>' value='<?php echo $data->$key ?>' />
		<!-- <span href="#" class="button" id='<?php echo $key ?>_upload' style='width: <?php echo $media_object[1] ?>px;'>Update image</span> -->
		<?php elseif ($value == 'text'): ?>
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