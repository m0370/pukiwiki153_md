<?php
// PukiWiki - Yet another WikiWikiWeb clone
// showrss.inc.php
// Copyright:
//     2002-2017 PukiWiki Development Team
//     2002      PANDA <panda@arino.jp>
//     (Original)hiro_do3ob@yahoo.co.jp
// License: GPL, same as PukiWiki
//
// Show RSS (of remote site) plugin
// NOTE:
//    * This plugin needs 'PHP xml extension'
//    * Cache data will be stored as CACHE_DIR/*.tmp

define('PLUGIN_SHOWRSS_USAGE', '#showrss(URI-to-RSS[,default|menubar|recent[,Cache-lifetime[,Show-timestamp]]])');

// Show related extensions are found or not
function plugin_showrss_action()
{
	if (PKWK_SAFE_MODE) die_message('PKWK_SAFE_MODE prohibit this');

	$body = '';
	foreach(array('xml', 'mbstring') as $extension){
		$$extension = extension_loaded($extension) ?
			'&color(green){Found};' :
			'&color(red){Not found};';
		$body .= '| ' . $extension . ' extension | ' . $$extension . ' |' . "\n";
	}
	return array('msg' => 'showrss_info', 'body' => convert_html($body));
}

function plugin_showrss_convert()
{
	static $_xml;

	if (! isset($_xml)) $_xml = extension_loaded('xml');
	if (! $_xml) return '#showrss: xml extension is not found<br />' . "\n";

	$num = func_num_args();
	if ($num == 0) return PLUGIN_SHOWRSS_USAGE . '<br />' . "\n";

	$argv = func_get_args();
	$timestamp = FALSE;
	$cachehour = 0;
	$template = $uri = '';
	switch ($num) {
	case 4: $timestamp = (trim($argv[3]) == '1');	/*FALLTHROUGH*/
	case 3: $cachehour = trim($argv[2]);		/*FALLTHROUGH*/
	case 2: $template  = strtolower(trim($argv[1]));/*FALLTHROUGH*/
	case 1: $uri       = trim($argv[0]);
	}

	$class = ($template == '' || $template == 'default') ? 'ShowRSS_html' : 'ShowRSS_html_' . $template;
	if (! class_exists($class)) $class = 'ShowRSS_html';

	if (! is_numeric($cachehour))
		return '#showrss: Cache-lifetime seems not numeric: ' . htmlsc($cachehour) . '<br />' . "\n";
	if (! is_url($uri))
		return '#showrss: Seems not URI: ' . htmlsc($uri) . '<br />' . "\n";

	// Remove old caches in 5% rate
	if (mt_rand(1, 20) === 1) {
		plugin_showrss_cache_expire(24);
	}
	list($rss, $time) = plugin_showrss_get_rss($uri, $cachehour);
	if ($rss === FALSE) return '#showrss: Failed fetching RSS from the server<br />' . "\n";
	if (! is_array($rss)) {
		// Show XML error message
		return '#showrss: Error - ' . htmlsc($rss) . '<br />' . "\n";
	}
	$time_display = '';
	if ($timestamp > 0) {
		$time_display = '<p style="font-size:10px; font-weight:bold">Last-Modified:' .
			get_date('Y/m/d H:i:s', $time) .  '</p>';
	}

	$obj = new $class($rss);
	return $obj->toString($time_display);
}

// Create HTML from RSS array()
class ShowRSS_html
{
	var $items = array();
	var $class = '';

	function ShowRSS_html($rss)
	{
		$this->__construct($rss);
	}
	function __construct($rss)
	{
		foreach ($rss as $date=>$items) {
			foreach ($items as $item) {
				$link  = $item['LINK'];
				$title = $item['TITLE'];
				$date = get_date_atom($item['_TIMESTAMP'] + LOCALZONE);
				$link = '<a href="' . $link . '" data-mtime="' .
					 $date . '" class="' . get_link_passage_class() .
					 '" rel="nofollow">' . $title . '</a>';
				$this->items[$date][] = $this->format_link($link);
			}
		}
	}

	function format_link($link)
	{
		return $link . '<br />' . "\n";
	}

	function format_list($date, $str)
	{
		return $str;
	}

	function format_body($str)
	{
		return $str;
	}

	function toString($timestamp)
	{
		$retval = '';
		foreach ($this->items as $date=>$items)
			$retval .= $this->format_list($date, join('', $items));
		$retval = $this->format_body($retval);
		return <<<EOD
<div{$this->class}>
$retval$timestamp
</div>
EOD;
	}
}

class ShowRSS_html_menubar extends ShowRSS_html
{
	var $class = ' class="small"';

	function format_link($link) {
		return '<li>' . $link . '</li>' . "\n";
	}

	function format_body($str) {
		return '<ul class="recent_list">' . "\n" . $str . '</ul>' . "\n";
	}
}

class ShowRSS_html_recent extends ShowRSS_html
{
	var $class = ' class="small"';

	function format_link($link) {
		return '<li>' . $link . '</li>' . "\n";
	}

	function format_list($date, $str) {
		return '<strong>' . $date . '</strong>' . "\n" .
			'<ul class="recent_list">' . "\n" . $str . '</ul>' . "\n";
	}
}

// Get and save RSS
function plugin_showrss_get_rss($target, $cachehour)
{
	$buf  = '';
	$time = NULL;
	if ($cachehour) {
		$filename = CACHE_DIR . encode($target) . '.tmp';
		// Remove expired cache
		plugin_showrss_cache_expire_file($filename, $cachehour);
		// Get the cache not expired
		if (is_readable($filename)) {
			$buf  = join('', file($filename));
			$time = filemtime($filename) - LOCALZONE;
		}
	}

	if (is_null($time)) {
		// Newly get RSS
		$data = pkwk_http_request($target);
		if ($data['rc'] !== 200) {
			return array(FALSE, 0);
		}
		$buf = $data['data'];
		$time = UTIME;

		// Save RSS into cache
		if ($cachehour) {
			$fp = fopen($filename, 'w');
			fwrite($fp, $buf);
			fclose($fp);
		}
	}
	// Parse
	$obj = new ShowRSS_XML();
	$obj->modified_date = (is_null($time) ? UTIME : $time);
	return array($obj->parse($buf),$time);
}

// Remove cache if expired limit exeed
function plugin_showrss_cache_expire($cachehour)
{
	$expire = $cachehour * 60 * 60; // Hour
	$dh = dir(CACHE_DIR);
	while (($file = $dh->read()) !== FALSE) {
		if (substr($file, -4) != '.tmp') continue;
		$file = CACHE_DIR . $file;
		$last = time() - filemtime($file);
		if ($last > $expire) unlink($file);
	}
	$dh->close();
}

/**
 * Remove single file cache if expired limit exeed
 * @param $filename
 * @param $cachehour
 */
function plugin_showrss_cache_expire_file($filename, $cachehour)
{
	$expire = $cachehour * 60 * 60; // Hour
	$last = time() - filemtime($filename);
	if ($last > $expire) {
		unlink($filename);
	}
}

// Get RSS and array() them
class ShowRSS_XML
{
	var $items;
	var $item;
	var $is_item;
	var $tag;
	var $encoding;
	var $modified_date;

	function parse($buf)
	{
		$this->items   = array();
		$this->item    = array();
		$this->is_item = FALSE;
		$this->tag     = '';
		$utf8 = 'UTF-8';
		$this->encoding = $utf8;
		// Detect encoding
		$matches = array();
		if(preg_match('/<\?xml [^>]*\bencoding="([a-z0-9-_]+)"/i', $buf, $matches)) {
			$encoding = $matches[1];
			if (strtoupper($encoding) !== $utf8) {
				// xml_parse() fails on non UTF-8 encoding attr in XML decLaration
				$buf = preg_replace('/<\?xml ([^>]*)\bencoding="[a-z0-9-_]+"/i', '<?xml $1', $buf);
				// xml_parse() requires UTF-8 compatible encoding
				$buf = mb_convert_encoding($buf, $utf8, $encoding);
			}
		}
		// Parsing
		$xml_parser = xml_parser_create($utf8);
		xml_set_element_handler($xml_parser, array(& $this, 'start_element'), array(& $this, 'end_element'));
		xml_set_character_data_handler($xml_parser, array(& $this, 'character_data'));
		if (! xml_parse($xml_parser, $buf, 1)) {
			return sprintf('XML error: %s at line %d in %s',
				xml_error_string(xml_get_error_code($xml_parser)),
				xml_get_current_line_number($xml_parser),
				(strlen($buf) < 500 ? $buf : substr($buf, 0, 500) . '...'));
		}
		xml_parser_free($xml_parser);
		return $this->items;
	}

	function escape($str)
	{
		// Unescape already-escaped chars (&lt;, &gt;, &amp;, ...) in RSS body before htmlsc()
		$str = strtr($str, array_flip(get_html_translation_table(ENT_COMPAT)));
		// Escape
		$str = htmlsc($str, ENT_COMPAT, $this->encoding);
		// Encoding conversion
		$str = mb_convert_encoding($str, SOURCE_ENCODING, $this->encoding);
		return trim($str);
	}

	// Tag start
	function start_element($parser, $name, $attrs)
	{
		if ($this->is_item !== FALSE) {
			$this->tag = $name;
			if ($this->is_item === 'ENTRY' && $name === 'LINK' && isset($attrs['HREF'])) {
				if (! isset($this->item[$name])) $this->item[$name] = '';
				$this->item[$name] .= $attrs['HREF'];
			}
		} else if ($name === 'ITEM') {
			$this->is_item = 'ITEM';
		} else if ($name === 'ENTRY') {
			$this->is_item = 'ENTRY';
		}
	}

	// Tag end
	function end_element($parser, $name)
	{
		if ($this->is_item === FALSE || $name !== $this->is_item) return;

		$item = array_map(array(& $this, 'escape'), $this->item);
		$this->item = array();

		if (isset($item['DC:DATE'])) {
			$time = plugin_showrss_get_timestamp($item['DC:DATE'], $this->modified_date);
		} else if (isset($item['PUBDATE'])) {
			$time = plugin_showrss_get_timestamp($item['PUBDATE'], $this->modified_date);
		} else if (isset($item['UPDATED'])) {
			$time = plugin_showrss_get_timestamp($item['UPDATED'], $this->time);
		} else {
			$time_from_desc = FALSE;
			if (isset($item['DESCRIPTION']) &&
				(($description = trim($item['DESCRIPTION'])) != '')) {
				$time_from_desc = strtotime($description);
			}
			if ($time_from_desc !== FALSE && $time_from_desc !== -1) {
				$time = $time_from_desc - LOCALZONE;
			} else {
				$time = time() - LOCALZONE;
			}
		}
		$item['_TIMESTAMP'] = $time;
		$date = get_date('Y-m-d', $item['_TIMESTAMP']);

		$this->items[$date][] = $item;
		$this->is_item        = FALSE;
	}

	function character_data($parser, $data)
	{
		if ($this->is_item === FALSE) return;
		if (! isset($this->item[$this->tag])) $this->item[$this->tag] = '';
		$this->item[$this->tag] .= $data;
	}
}

function plugin_showrss_get_timestamp($str, $default_date)
{
	$str = trim($str);
	if ($str == '') return UTIME;

	$matches = array();
	if (preg_match('/(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(([+-])(\d{2}):(\d{2}))?/', $str, $matches)) {
		$time = strtotime($matches[1] . ' ' . $matches[2]);
		if ($time === FALSE || $time === -1) {
			$time = $default_date;
		} else if (isset($matches[3])) {
			$diff = ($matches[5] * 60 + $matches[6]) * 60;
			$time += ($matches[4] == '-' ? $diff : -$diff);
		}
		return $time;
	} else {
		$time = strtotime($str);
		return ($time === FALSE || $time === -1) ? $default_date : $time - LOCALZONE;
	}
}
