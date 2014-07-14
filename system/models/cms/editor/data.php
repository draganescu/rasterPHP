<form action="" method="post" class="bootstrap-frm">
	<input type="hidden" name='csrf' value='<?php echo $csrf ?>'>
	<input type="hidden" name='raster_action' value='save_page'>
	<input type="hidden" name='page_name' value='<?php echo $type ?>'>
	<input type="hidden" name='variable_name' value='<?php echo util::post('name') ?>'>
	<h1>Edit <?php echo ucfirst(str_replace('_', ' ', util::post('name'))) ?>
	<span>Using the list below you can edit and delete <?php echo ucfirst(str_replace('_', ' ', util::post('name'))) ?> data</span></h1>
	<table width="100%" class="data_list">
		<thead>
			<tr>
				<?php foreach ($fields as $name => $details): ?>
				<th><?php echo ucfirst(str_replace('_',' ',$name)) ?></th>
				<?php endforeach ?>
				<th>Action</th>
			</tr>
			
		</thead>
		<tbody>

			<?php foreach ($data as $key => $item): ?>
			<tr>
				<?php foreach ($fields as $name => $details): ?>
				<td><?php echo substr(strip_tags($item->$name), 0, 20) ?></td>
				<?php endforeach ?>	
				<td>
					<a href="#" class="data_editor" data-rel='<?php echo $item->id ?>' data-name='<?php echo util::post('name') ?>'>Edit</a>
					<?php if ($item->id > 1): ?>
					&nbsp;/&nbsp;
					<a href="#" class="data_remover" data-rel='<?php echo $item->id ?>' data-name='<?php echo util::post('name') ?>'>Remove</a>
					<?php endif ?>
				</td>
			</tr>
			<?php endforeach ?>
			
		</tbody>
	</table>
</form>