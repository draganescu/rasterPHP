<?php
// A model that the_events.php stops from loading (loading_model_secret)
class secret {
	function code() {
		return 'the secret leaked';
	}
}
