<?php $name = (string)util::post('name'); $label = ucfirst(str_replace('_', ' ', $name)); ?>
<form action="" method="post" class="bootstrap-frm">
	<h1>Edit <?php echo util::e($label) ?>
	<a class="data_adder" href="#" data-name="<?php echo util::e($name) ?>">Add <?php echo util::e($label) ?></a>
	<span>Using the list below you can edit and delete <?php echo util::e($label) ?> data</span></h1>
	<table width="100%" class="data_list">
		<thead>
			<tr>
				<?php foreach ($fields as $field => $details): ?>
				<th><?php echo util::e(ucfirst(str_replace('_',' ',$field))) ?></th>
				<?php endforeach ?>
				<th>Action</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($data as $key => $item): ?>
			<tr>
				<?php foreach ($fields as $field => $details): ?>
				<td><?php echo util::e(mb_substr(strip_tags((string)$item->$field), 0, 20)) ?></td>
				<?php endforeach ?>	
				<td>
					<a href="#" class="data_editor" data-rel="<?php echo (int)$item->id ?>" data-name="<?php echo util::e($name) ?>">Edit</a>
					&nbsp;/&nbsp;
					<a href="#" class="data_remover" data-rel="<?php echo (int)$item->id ?>" data-name="<?php echo util::e($name) ?>">Remove</a>
				</td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</form>
