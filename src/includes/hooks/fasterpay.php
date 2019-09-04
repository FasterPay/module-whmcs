<?php
add_hook('AdminAreaPage', 1, function($vars) {
    if ($vars['filename'] == 'invoices'
    	&& !empty($_GET['action'])
    	&& $_GET['action'] == 'edit'
    	&& !empty($_GET['id'])
    	&& !empty($_GET['refundattempted'])
    	&& !empty($_GET['refund_result_msg'])) {
    	$infoboxContent = explode(':', $_GET['refund_result_msg']);
    	$infoboxContent = is_array($infoboxContent) ? $infoboxContent : null;

    	if (!empty($infoboxContent) && $infoboxContent[0] == 'custom') {
	    	echo "
	    		<style>
	    			.infobox {
	    				display:none;
	    			}
	    		</style>
	    		<script>
	    			window.addEventListener('DOMContentLoaded', function() {
	    				var infobox = document.querySelector('.infobox');
	    				if (infobox) {
	    					var infobox_title = '{$infoboxContent[1]}';
	    					var infobox_content = '{$infoboxContent[2]}';
	    					infobox.innerHTML = `
	    						<strong>
	    							<span class='title'>` + infobox_title + `</span>
	    						</strong>
	    						<br>
								` + infobox_content + `
	    					`;
	    				}
	    				infobox.style.display = 'block';
	    			});
	    		</script>
	    	";
	    }
    }
});