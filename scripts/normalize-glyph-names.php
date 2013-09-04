#!/usr/bin/php
<?php
define('DAY_SECONDS', 60 * 60 * 24);

$sfd = file_get_contents($argv[1]);

$create_time = 1278388800;
$modify_time = floor(time() / DAY_SECONDS) * DAY_SECONDS;
$version = date('Ymd');

list($head, $body) = explode('BeginChars', $sfd);

$head_lines = explode("\n", $head);
$inside_kern = FALSE;
$kern_preamble = '';
$kern_table_lines = array();
$old_kern_table = '';
foreach ($head_lines as $head_line) {
	if (substr($head_line, 0, 12) == 'KernClass2: ') {
		$kern_preamble = $head_line;
		$inside_kern = TRUE;
		$old_kern_table = $head_line . "\n";
	}
	elseif ($inside_kern) {
		if (substr($head_line, 0, 1) == ' ') {
			$old_kern_table .= $head_line . "\n";
			$glyph_list = explode(' ', trim($head_line));
			$offset = array_shift($glyph_list);
			$kern_classes[] = array('offset' => $offset, 'glyphs' => $glyph_list);
		}
		else {
			$inside_kern = FALSE;
			break;
		}
	}
}

$glyph_names = array();
$glyphs = array();
$max_index = 0;
preg_match_all('!StartChar: .*EndChar!Ums', $body, $glyph_matches, PREG_SET_ORDER);
foreach ($glyph_matches as $glyph_match) {
	$glyph_block = $glyph_match[0];
	$substitutions = $ligatures = array();
	preg_match('!StartChar: ([A-Za-z0-9_.]+)!', $glyph_block, $matches);
	$glyph_name = $matches[1];
	preg_match('!Encoding: ([0-9]+) ([0-9-]+) [0-9]+!', $glyph_block, $matches);
	list (, $internal_index, $unicode) = $matches;
	$internal_index = max($max_index, $internal_index);
	$sub_table = '';
	if (preg_match('!Substitution2: "([^"]+)" (.+)\n!', $glyph_block, $matches)) {
		$sub_table = $matches[1];
		$substitutions = explode(' ', trim($matches[2]));
	}
	$lig_table = '';
	if (preg_match('!Ligature2: "([^"]+)" (.+)\n!', $glyph_block, $matches)) {
		$lig_table = $matches[1];
		$ligatures = explode(' ', trim($matches[2]));
	}
	if ($unicode > 255) {
		$new_glyph_name = 'uni' . str_pad(strtoupper(dechex($unicode)), 4, '0', STR_PAD_LEFT);
	}
	else {
		$new_glyph_name = $glyph_name;
	}
	$glyph_names[$glyph_name] = $new_glyph_name;
	$glyphs[$glyph_name] = array(
		'data' => $glyph_block,
		'lig_table' => $lig_table,
		'ligatures' => $ligatures,
		'sub_table' => $sub_table,
		'substitutions' => $substitutions
	);
}

foreach ($glyphs as $old_name => $glyph) {
	if (strpos($old_name, '.') > 0) {
		list($root, $ext) = explode('.', $old_name, 2);
		if (array_key_exists($root, $glyph_names)) {
			$glyph_names[$old_name] = $glyph_names[$root] . '.' . $ext;
		}
	}
}
foreach ($glyphs as $old_name => $glyph) {
	if (substr($glyph_names[$old_name], 0, 4) == 'uniE' && count($glyphs[$old_name]['ligatures']) > 0) {
		$parts = array();
		foreach ($glyph['ligatures'] as $member_old) {
			$parts[] = $glyph_names[$member_old];
		}
		$glyph_names[$old_name] = join('_', $parts);
	}
}
foreach ($glyphs as $old_name => $glyph) {
	if (count($glyph['ligatures']) > 0) {
		$ligs = array();
		foreach ($glyph['ligatures'] as $lig_glyph) {
			$ligs[] = $glyph_names[$lig_glyph];
		}
		$glyphs[$old_name]['ligatures'] = $ligs;
	}
	if (count($glyph['substitutions']) > 0) {
		$subs = array();
		foreach ($glyph['substitutions'] as $sub_glyph) {
			$subs[] = $glyph_names[$sub_glyph];
		}
		$glyphs[$old_name]['substitutions'] = $subs;		
	}
}

$new_kern_table = $kern_preamble . "\n";
for ($i = 0; $i < count($kern_classes); $i++) {
	$offset = $kern_classes[$i]['offset'];
	$kern_glyphs = $kern_classes[$i]['glyphs'];
	$new_kern_table .= " $offset";
	if ($i == count($kern_classes) - 1) {
		$new_kern_table .= ' ' . join(' ', $kern_glyphs);
	}
	else {
		foreach ($kern_glyphs as $kern_glyph) {
			$new_kern_table .= ' ' . $glyph_names[$kern_glyph];
		}
	}
	$new_kern_table .= "\n";
}

$head = str_replace($old_kern_table, $new_kern_table, $head);
$head = preg_replace('!CreationTime: [0-9]+!', "CreationTime: $create_time", $head);
$head = preg_replace('!ModificationTime: [0-9]+!', "ModificationTime: $modify_time", $head);
$head = preg_replace('!Version: [0-9]{8}!', "Version: $version", $head);
$head = preg_replace('!LangName: 1033 "([^"]*)" "([^"]*)" "([^"]*)" "([^"]*)" "([^"]*)" "Version [0-9]{8}"!', 'LangName: 1033 "$1" "$2" "$3" "$4" "$5" "Version ' . $version . '"', $head);
$head = preg_replace('!WinInfo: [0-9]+ [0-9]+ [0-9]+!', 'WinInfo: 64 16 4', $head);

print $head;
print "BeginChars: $max_index " . count($glyphs) . "\n\n";

foreach ($glyphs as $old_name => $glyph) {
	$new_name = $glyph_names[$old_name];
	$data = $glyph['data'];
	$lines = explode("\n", $data);
	if ($new_name != $old_name) {
		$lines[0] = "StartChar: $new_name";
	}
	if (count($glyph['substitutions']) > 0) {
		$table = $glyph['sub_table'];
		$sub_glyphs = join(' ', $glyph['substitutions']);
		for ($i = 1; $i < count($lines); $i++) {
			if (substr($lines[$i], 0, 15) == 'Substitution2: ') {
				$lines[$i] = "Substitution2: \"$table\" $sub_glyphs";
				break;
			}
		}
	}
	if (count($glyph['ligatures']) > 0) {
		$table = $glyph['lig_table'];
		$lig_glyphs = join(' ', $glyph['ligatures']);
		for ($i = 1; $i < count($lines); $i++) {
			if (substr($lines[$i], 0, 11) == 'Ligature2: ') {
				$lines[$i] = "Ligature2: \"$table\" $lig_glyphs";
				break;
			}
		}
	}
	print "\n" . join("\n", $lines) . "\n";
}
print "EndChars\nEndSplineFont\n";



