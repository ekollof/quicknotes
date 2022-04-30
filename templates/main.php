<?php
vendor_script('quicknotes', 'handlebars');
script('quicknotes', 'templates');
vendor_script('quicknotes', 'isotope.pkgd');
vendor_script('quicknotes', 'medium-editor');
vendor_style('quicknotes', 'medium-editor');
vendor_script('quicknotes', 'autolist');
vendor_script('quicknotes', 'lozad');
//vendor_script('quicknotes', 'colorPick');
//vendor_style('quicknotes', 'colorPick');
script('quicknotes', 'qn-dialogs');
script('quicknotes', 'script');
style('quicknotes', 'style');
style('quicknotes', 'medium');
?>

	<div id="app-navigation">
		<?php print_unescaped($this->inc('part.navigation')); ?>
		<?php print_unescaped($this->inc('part.settings')); ?>
	</div>

	<div id="app-content">
		<div id="app-content-wrapper">
			<?php print_unescaped($this->inc('part.content')); ?>
		</div>
	</div>
