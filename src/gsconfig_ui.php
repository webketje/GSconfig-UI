<?php
/*
Plugin Name: GSconfig UI
Description: UI shim for gsconfig.php. Tweak config settings directly from GS CMS.
             Requires GS Custom Settings 0.4+
Version: 0.1
Author: Kevin Van Lierde
Author URI: http://webketje.github.io
*/

// add in this plugin's language file
i18n_merge('gsonfig_ui') || i18n_merge('gsconfig_ui', 'en_US');

/* 4 more to be tested: custom_salt, login_salt, editor_options, admin_folder */
/* 1 to be modified gs_editor_toolbar_custom */
# register plugin
register_plugin('gsconfig_ui',          # ID of plugin, should be filename minus php
  i18n_r('gsconfig_ui/PLUGIN_NAME'),    # Title of plugin
  '0.1',                                # Version of plugin
  'Kevin Van Lierde',                   # Author of plugin
  'http://webketje.github.io',          # Author URL
  i18n_r('gsconfig_ui/PLUGIN_DESCR'),   # Plugin Description
  'plugins'                             # Page type of plugin
);

// hooks
add_action('custom-settings-load', 'gsconfig_ui_load');
add_action('custom-settings-save', 'gsconfig_ui_update');
add_action('custom-settings-render-top', 'custom_settings_render', array('gsconfig_ui', 'gsconfig_ui_output'));
add_action('custom-settings-render-bottom', 'custom_settings_render', array('gsconfig_ui', 'gsconfig_ui_scripts'));
add_action('successful-login-start', 'gsconfig_ui_setpwd');

function gsconfig_ui_output() 
{  ?>
	<style>
		#salt-generator { padding: 3px 5px; }
		#salt-generator-output { padding: 2px 4px; -moz-border-radius: 3px; -webkit-border-radius: 3px; -o-border-radius: 3px; border-radius: 3px; border: 1px solid #ddd; outline: none; max-width: 95%;}
		#custom-toolbar-builder { transition: .5s height; width: 646px; margin-top: 20px; position: relative; left: -5000px; border: 1px solid #ccc;  height: 0; }
		#custom-toolbar-builder-toggle { position: absolute; margin-top: 6px; margin-left: 5px; }
		#toolbar-gen { padding-left: 20px; }
	</style>
	<input type="button" id="salt-generator" class="submit" onclick="saltGeneratorString()" value="<?php i18n('gsconfig_ui/GEN_SALT'); ?>">
	<input type="text" id="salt-generator-output">
	<br><br><i id="custom-toolbar-builder-toggle" class="fa fa-plus"></i>
	<input id="toolbar-gen" type="button" class="button" value="<?php i18n('gsconfig_ui/CUSTOM_TOOLBAR'); ?>" onclick="toggleToolbarGen()">
	<iframe id="custom-toolbar-builder" src="http://rawgit.com/webketje/8c949d57beffe097a770/raw/cd99027f3cd9038fae9e11f62489e0cab662d458/index.html"></iframe>
	<script type="text/javascript">
		function toggleToolbarGen(e) { 
			var d = document.getElementById('custom-toolbar-builder'), 
			s = document.getElementById('custom-toolbar-builder-toggle'); 
			if (!d.style.marginLeft) 
				d.style.left = '0px';
			s.className = d.style.height == '0px' ? 'fa fa-plus' : 'fa fa-minus'; 
			d.style.borderWidth = d.style.height == '0px' || !d.style.height ? '1px' : '0px'; 
			d.style.height = d.style.height == '0px' || !d.style.height ? '290px' : '0px';  
		}
		function saltGeneratorString() {
			var chars = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXTZabcdefghiklmnopqrstuvwxyz !-^$=:|#*%~+?";
			var string_length = 55;
			var randomstring = '';
			for (var i=0; i<string_length; i++) {
				var rnum = Math.floor(Math.random() * chars.length);
				randomstring += chars.substring(rnum,rnum+1);
			}
			document.getElementById('salt-generator-output').value = randomstring;
			document.getElementById('salt-generator-output').select();
		}
		addHook(function() {
			var adminSetting = GSCS.returnSetting('gsconfig_ui', 'gs_admin');
			adminSetting.value.subscribe(function(value) {
				document.getElementById('custom-settings-save-btn').onclick = function() {
					if (!(GLOBAL.ADMINDIR === 'admin' && (value === '' || value == 'admin'))) {
						setTimeout(function() {
							location.href = location.href.replace(GLOBAL.ADMINDIR, trim(value) ? value : 'admin');
						}, 3000);
					}
				}
			});
		});
	</script>
	<?php 
}
function gsconfig_ui_setpwd() 
{
	global $password, $user_xml;
	$datau = getXML($user_xml);
	$datau->PWD = passhash($password);
	XMLsave($datau, GSUSERSPATH . strtolower($datau->USR) . '.xml');
}
// on settings load, cache to global to compare whether any setting has changed
function gsconfig_ui_load() 
{
	global $gsconfig;
	$settings = $gsconfig = array();
	$temp = return_setting_group('gsconfig_ui', 'gs', false);
	foreach ($temp as $l=>$s) {
		if ($s['type'] !== 'section-title')	{
			$settings[$s['constant']] = array_merge($s, array('lookup', $l));
			$gsconfig[$s['constant']] = $s['value'] === $s['default'] ? null : $s['value']; }
	}
	$settings = array_reduce($settings, 'gsconfig_ui_flatten_setting');
	file_put_contents(GSPLUGINPATH . 'gsconfig_ui/temp_data.txt', $settings);
	exec_action('gsconfig-load');
}

// before settings save, save to gsconfig.php
function gsconfig_ui_update() 
{
	global $gsconfig_ui_settings_presave;
	
	$ss = return_setting_group('gsconfig_ui', 'gs', false);
	$gsconfig_ui_settings_presave = array();
	foreach ($ss as $l => $s) {
		if ($s['type'] !== 'section-title')	$gsconfig_ui_settings_presave[$s['constant']] = array_merge($s, array('lookup', $l));
	}
	$comp_load = file_get_contents(GSPLUGINPATH . 'gsconfig_ui/temp_data.txt');
	$comp_save = array_reduce($gsconfig_ui_settings_presave, 'gsconfig_ui_flatten_setting');
	if ($comp_load !== $comp_save) {
		$rgx = '~(#* *?)(define\()(.*?),(.*)(\);)~';
		$gssf = false;
		$path = GSROOTPATH . 'gsconfig.php';
		$f = file_get_contents($path);
		$output = preg_replace_callback($rgx, 'gsconfig_ui_iterate', $f);
		$locrep = $ss['php_locale'];
		$output = preg_replace('~#* *setlocale.*?\);~', ($locrep['value'] ? 'setlocale(LC_ALL, \''. $locrep['value'] . '\');' : '#setlocale(LC_ALL, \'en_US\');'), $output);
		file_put_contents($path, $output);
	}
	unlink(GSPLUGINPATH . 'gsconfig_ui/temp_data.txt');
}

function gsconfig_ui_flatten_setting($carry, $key) 
{ 
	return $carry .= ' ' . $key['value']; 
}

function gsconfig_ui_iterate($match) 
{
	// $r = result, $gsconfig_ui_settings_presavei = dictionary, $m = matches, $l = lookup, $s = setting
	global $gsconfig_ui_settings_presave,  $gssf, $USR;
	$m = array(
		'full'  => $match[0],
		'hash'  => $match[1],
		'def'   => $match[2],
		'const' => $match[3],
		'val'   => str_replace('\'', '', $match[4]),
		'end'   => $match[5]
	);
	$r = $m['full'];
	$l = str_replace('\'', '', $m['const']);
	if (isset($gsconfig_ui_settings_presave[$l]) && $gsconfig_ui_settings_presave[$l]['value'] !== $m['val']) {
		$s = $gsconfig_ui_settings_presave[$l];
		switch ($s['lookup']) {
			// first batch of settings are inverted 
			// (eg default is true in gsconfig => false in settings)
			// commented out if default (with true so users can still uncomment manually)
			// $s['default'] holds the default the UI, not gsconfig.php
			// set to true if not
		  case 'gs_cdn': 
		  case 'gs_csrf':
			case 'gs_highlight':
			case 'gs_apache_check':
			case 'gs_ver_check':
			case 'gs_sitemap': 
			case 'gs_uploadify':
			case 'gs_canonical':
			case 'gs_auto_meta_descr':
				$r = $m['def'] . $m['const'] . ',true' . $m['end'];
				if ($s['value'] === $s['default'])	$r = '#' . $r;
				break;
			// only checkbox setting where false in GS  = false in the UI
			case 'gs_debug':
				$r = $m['def'] . $m['const'] . ', true' . $m['end'];
				if (!$s['value']) $r = '#' . $r;
				break;
			// only radio option
			case 'gs_editor_toolbar':
				switch ($s['value']) {
					case 0:
						$r = $m['def'] . $m['const'] . ',\'[]\'' . $m['end'];
						break;
					case 1: 
						$r = '#' . $m['def'] . $m['const'] . ',\'advanced\'' . $m['end'];
						break;
					case 2:
						$r = $m['def'] . $m['const'] . ',\'advanced\'' . $m['end'];
						break;
					case 3: 
						$r = $m['def'] . $m['const'] . ',\'[["Source","Save","NewPage","DocProps","Preview","Print","Templates"], ["Cut","Copy","Paste","PasteText","PasteFromWord","Undo","Redo"], ["Find","Replace","SelectAll","SpellChecker","Scayt"], ["Form","Checkbox","Radio","TextField","Textarea","Select","Button","ImageButton","HiddenField"], ["Bold","Italic","Underline","Strike","Subscript","Superscript","RemoveFormat"], ["NumberedList","BulletedList","Outdent","Indent","Blockquote","CreateDiv","JustifyLeft","JustifyCenter","JustifyRight","JustifyBlock","BidiLtr","BidiRtl"], ["Link","Unlink","Anchor"], ["Image","Flash","Table","HorizontalRule","Smiley","SpecialChar","PageBreak","Iframe"], ["Styles","Format","Font","FontSize"], ["TextColor","BGColor"], ["Maximize","ShowBlocks","About"]]\'' . $m['end'];
						break;
					case 4:
						$r = $m['def'] . $m['const'] . ',\'' . return_setting('gsconfig_ui','gs_editor_toolbar_custom') . '\'' . $m['end'];
						break;
				};
				break;
			case 'gs_editor_lang':
				$r = $m['def'] . $m['const'] . ',\'' . $s['options'][$s['value']] . '\'' . $m['end'];
				if ($s['value'] === $s['default']) $r = '#' . $r;
				break;
			case 'gs_editor_height':
				$r = $m['def'] . $m['const'] . ',\'' . $s['value'] . '\'' . $m['end'];
				if ($s['value'] === $s['default']) $r = '#' . $r;
				break;
			case 'gs_chmod':
			case 'gs_autosave':
				$r = (!$s['value'] ? '#' : '') . $m['def'] . $m['const'] . ',' . $s['value'] . $m['end'];
				break;
		  // text settings commented out by default
			case 'gs_login_salt':
			case 'gs_custom_salt':
				require_once(GSADMININCPATH . 'configuration.php');
				global $SALT, $cookie_time, $cookie_name;
				$SALT = sha1($s['value']);
				kill_cookie($cookie_name);
				create_cookie();
			case 'gs_editor_options':
			case 'gs_timezone':
			case 'gs_admin': 
				rename(GSADMINPATH, GSROOTPATH . $s['value'] .'/');
			case 'gs_from_email':
				$r = (!$s['value'] ? '#' : '') . $m['def'] . $m['const'] . ',\'' . $s['value'] . '\'' . $m['end'];
				break;
		  // following 3 are not commented out by default
			case 'gs_suppress_errors':
				$r = ($s['value'] ? '' : '#') . $m['def'] . $m['const'] . ',' . ($s['value'] ? 'true' : 'false') . $m['end'];
				break;
			case 'gs_ping':
				$r = ($s['value'] ? '#' : '') . $m['def'] . $m['const'] . ',' . $s['default'] . $m['end'];
				break;
			case 'gs_image_width':
				$r = $m['def'] . $m['const'] . ',\'' . (!$s['value'] ? '200' : $s['value']) . '\'' . $m['end'];
				break;
			case 'gs_editor_height':
				$r = ($s['value'] ? '#' : '') . $m['def'] . $m['const'] . ',\'' . $s['value'] . '\'' . $m['end'];
				break;
			case 'gs_merge_lang': 
				$r = (!$s['value'] || $s['value'] === 'en_US' ? '#' : '') . $m['def'] . $m['const'] . ',\'' . $s['value'] . '\'' . $m['end'];
				break;
			case 'gs_style': 
				$options = array('', 'GSSTYLE_SBFIXED', 'implode(\',\',array(GSSTYLEWIDE,GSSTYLE_SBFIXED))','GSSTYLEWIDE');
				if (!$gssf) {
					$r = ($s['value'] === 0 ? '#' : '') . $m['def'] . $m['const'] . ',' . $options[$s['value']] . $m['end'];
					$gssf = true;
					if (function_exists('delete_cache')) 
						delete_cache();
				}
				break;
			default: 
				$r = $m['full'];
		}
	}
	return $r;
}