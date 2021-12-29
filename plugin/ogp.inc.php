<?php

// 'ogp' plugin for PukiWiki
// author: m0370
// Twitter: @m0370

// ver1.0 (2019.9.10)
// ひとまずOGPを取得して表示する機能を実装しました。

// ver1.1 (2019.9.17)
// Cache機能を実装しました。CACHE.DIRのogpというサブフォルダにキャッシュを配置します。

// ver1.2 (2020.5.1)
// 第2引数をスタイルシートとして引用

// ver1.3 (2020.5.2)
// ファイル形式（GIF・PNGなど）を反映したキャッシュファイル名になるようにしました。従来のキャッシュも利用できます。

// ver 1.4 (2020.7.16)
// HTMLパース,noimg対応
// OGP画像がjpg拡張子になっていても中身がwebPやgzipの場合に画像が表示できない問題がある

// ver 1.5 (2021.6.14)
// キャッシュをJSON形式で保存するように変更

// ver 1.6 (2021.12.23)
// PHP8.0でエラーが出ないよう微修正

// WEBPファイルがあるときWEBP表示を試みる fallback
define('PLUGIN_OGP_WEBP_FALLBACK', TRUE); // TRUE, FALSE

// 画像サイズ
$ogpsize = '100';

/////////////////////////////////////////////////

function plugin_ogp_convert()
{
	$args = func_get_args();
	$uri = get_script_uri();
	$ogpurl = (explode('://', $args[0]));
	$ogpurlmd = md5($ogpurl[1]);
	$datcache = CACHE_DIR . 'ogp/' . $ogpurlmd . '.txt';
	$gifcache = CACHE_DIR . 'ogp/' . $ogpurlmd . '.gif';
	$jpgcache = CACHE_DIR . 'ogp/' . $ogpurlmd . '.jpg';
	$pngcache = CACHE_DIR . 'ogp/' . $ogpurlmd . '.png';
	if(PLUGIN_OGP_WEBP_FALLBACK) {
	$webpcache = CACHE_DIR . 'ogp/' . $ogpurlmd . '.webp'; //webp対応
	}
	
	if(file_exists($pngcache)) { $imgcache = $pngcache ; }
	else if(file_exists($gifcache)) { $imgcache = $gifcache ; }
	else { $imgcache = $jpgcache ; }
	
	if(file_exists($datcache) && file_exists($imgcache)) {
		$ogpcache = json_decode(file_get_contents($datcache), true);
		$title = $ogpcache['title'];
		$description = $ogpcache['description'];
		$src = $imgcache ;
	} else {
	    require_once(PLUGIN_DIR.'opengraph.php');
	    $graph = OpenGraph::fetch($args[0]);
	    if ($graph) {
	        $title = $graph->title;
	        $url = $graph->url;
	        $description = $graph->description;
	        if( isset($graph->{'image:secure_url'}) ){
			 	$src = $graph->{'image:secure_url'};
			} else {
				$src = $graph->image;
			}
			if( substr($src, 0, 2) === '//'){$src = 'https:' . $src;}
		    $title_check = utf8_decode($title);
		    $description_check = utf8_decode($description);
		    if(mb_detect_encoding($title_check) == 'UTF-8'){
		        $title = $title_check; // 文字化け解消
		    }
		    if(mb_detect_encoding($description_check) == 'UTF-8'){
		        $description = $description_check; // 文字化け解消
		    }
		    
		    $detects = array('ASCII','EUC-JP','SJIS','JIS','CP51932','UTF-16','ISO-8859-1');
		    
		    // 上記以外でもUTF-8以外の文字コードが渡ってきてた場合、UTF-8に変換する
		    if(mb_detect_encoding($title) != 'UTF-8'){
		        $title = mb_convert_encoding($title, 'UTF-8', mb_detect_encoding($title, $detects, true));
		    }
		    if(mb_detect_encoding($description) != 'UTF-8'){
		        $description = mb_convert_encoding($description, 'UTF-8', mb_detect_encoding($description, $detects, true));
		    }

		    $grapharray = array('title' => $title, 'description' => $description, 'src' => $src, 'url' => $args[0], 'date' => date("Y-m-d H:i:s"));
			file_put_contents($datcache, json_encode($grapharray, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

			if(file_exists($imgcache)) {
				$src = $imgcache ;
			} else if($src == '') {
				$is_noimg = TRUE ;
				$imgfile = touch('imgfile.jpg');
				file_put_contents($jpgcache, $imgfile) ;
			} else {
				$imgfile = file_get_contents($src);
				$filetype = exif_imagetype($src);
				if( $filetype == IMAGETYPE_GIF ){
					file_put_contents($gifcache, $imgfile) ;
				} else if ( $filetype == IMAGETYPE_PNG ){
					file_put_contents($pngcache, $imgfile) ;
				} else {
					file_put_contents($jpgcache, $imgfile) ;
				} //どの拡張子でもない場合、ダミーjpgファイルを作る
			}
		} else return '#ogp Error: Page not found.';
	}

	if($is_noimg != TRUE){
		$is_noimg = (in_array('noimg', $args) || ( file_exists($imgcache) && filesize($imgcache) <= 1 ));
	}
	if($is_noimg) {$noimgclass = "ogp-noimg" ;}
 	if(in_array('ogp2', $args)) {$ogpclass = 'ogp2';} //ogp2

//XSS回避
	$description = htmlspecialchars($description);
	$args[0] = htmlspecialchars($args[0]);

//WEBP表示のfallback
	if ( PLUGIN_OGP_WEBP_FALLBACK && file_exists($webpcache)) {
		$fallback1 = '<picture><source type="image/webp" data-srcset="' . $webpcache . '"/>';
		$fallback2 = '</picture>';
	} else {
		$fallback1 = '';
		$fallback2 = '';
	}

return <<<EOD
<div class="ogp $ogpclass">
<div class="ogp-img-box $ogpclass $noimgclass">$fallback1<img class="ogp-img $ogpclass lazyload" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" data-src="$src" alt="$title" width="$ogpsize" height="$ogpsize">$fallback2</div>
<div class="ogp-title $ogpclass"><a href="$args[0]" target=”_blank” rel="noreferrer">$title<span class="overlink"></span></a></div>
<div class="ogp-description $ogpclass">$description</div>
<div class="ogp-url $ogpclass">$args[0]</div>
</div>
EOD;
}

?>