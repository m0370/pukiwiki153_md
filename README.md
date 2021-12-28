もともとpukiwiki 1.5.0を無理やりMarkdown対応したという記事がqiitaにありました。確かにタイトルにあるとおり無理やり腕力でMarkdownにしたものなのでやや強引な感じは否めませんが、非常に興味深いものです。

[Pukiwikiを無理やりMarkdown記法に変えてみた - Qiita](https://qiita.com/devneko/items/fafac4ade37c9cb3d2f4)

とりあえず実現した PukiwikiがMarkdownで書けたらいいなと思ったPukiwikiユーザーさんはたくさんいらっしゃると思います。 いないですかね…… 僕はそういったものがどうしても欲しくて、PukiwikiライクでM...

https://qiita.com/devneko/items/fafac4ade37c9cb3d2f4

### イヤでもデファクトスタンダードになっているMarkdown[ ](https://oncologynote.com/?2a74686299#me2031b1)

もともと文法のわかりやすさや機能性はpukiwiki記法のほうがMarkdownより優れていると思います。リンクも分かりやすいし、文字色などのスタイリングもpukiwiki記法の方が柔軟です。しかし、世の中はますまずMarkdown記法が使える局面が増えてきています。

Windows/Macを問わずMarkdownを書くためのアプリは増えていますし、iPadなどで文書を書く人にもMarkdown記法は使いやすい書式になっています。もはやMicrosoft  OfficeのWordで書かれる文書よりもMarkdownのほうが多いのではないでしょうか。そういう時代ですから、pukiwikiもMarkdownで書ければ良いのになと思っていたのでした。

### pukiwiki-mdをまねてPukiwiki 1.5.3をpukiwiki153-md化する[ ](https://oncologynote.com/?2a74686299#v90e5d90)

そこで、pukiwiki 1.5.0で止まっていた無理やりMarkdown化をpukiwiki  1.5.3に応用してみることにしました。色々と試行錯誤していますが、まだ十分には動作検証はできていません。それでも、なんとかpukiwiki  1.5.3のMarkdown版として動くところまでは来ました。PHP 7.4で動作検証済みです。

### サンプル[ ](https://oncologynote.com/?2a74686299#bf6a73a7)

```
#hoge
文章です。
[[FrontPage]]で内部リンクを張れる。
もちろん[ほげほげ](http://example.com)というMarkdownのリンクの書き方もできる。

##リスト
- foo
- bar

##画像を表示(Pukiwikiプラグインが使える)
!ref(テスト/test.jpg)
```

## 改装箇所[ ](https://oncologynote.com/?2a74686299#sceda08c)

### vendorフォルダにMarkdown parserをインストール[ ](https://oncologynote.com/?2a74686299#x59b2f9c)

Markdown parserは何を使っても良いが、今回は erusev/parsedown を使用しています。

https://github.com/erusev/parsedown/wiki/

### index.phpでMarkdown parserを読み込む設定をする[ ](https://oncologynote.com/?2a74686299#d6635a72)

index.php に <?php require('vendor/autoload.php'); ?>を加えておく必要がある。

### lib/convert_html.php[ ](https://oncologynote.com/?2a74686299#d66d18c1)

22-25行目をコメントアウトして書き換える

```
$body = new Body(++$contents_id);
$body->parse($lines);
	
return $body->toString();
```

上記のようになっているところを、下記のように書き換える。

```
foreach ( $lines as &$line ) {
	$matches = array();
	if ( preg_match('/^\\!([a-zA-Z0-9_]+)(\\(([^\\)\\n]*)?\\))?/', $line, $matches) ) {
		$plugin = $matches[1];
		if ( exist_plugin_convert($plugin) ) {
			$name = 'plugin_' . $matches[1] . '_convert';
			$params = array();
			if ( isset($matches[3]) ) {
				$params = explode(',', $matches[3]);
			}
			$line = call_user_func_array($name, $params);
		} else {
			$line = "plugin ${plugin} failed.";
		}
	} else {
		$line = make_link($line); //この行をコメントアウトしてmake_linkをスキップするとraw HTMLを書けるようになる一方でPukiwiki方式のリンクなどの書式が使えなくなる
		// ファイル読み込んだ場合に改行コードが末尾に付いていることがあるので削除
		// 空白は削除しちゃだめなのでrtrim()は使ってはいけない
        $line = str_replace(array("\r\n","\n","\r"), "", $line);
	}
}
unset($line);

$text = implode("\n", $lines);

$parsedown = new \Parsedown(); //Parsedown→ParsedownExtraに変更しても良い
$result = $parsedown ->setBreaksEnabled(true) ->text($text); // ->setBreaksEnabled(true)を付けて改行を可能にしている

return $result;
```

また、今後Pukiwiki 1.5.4が正式リリースされると思いますので、PHP 8.0の対応を見据えて下記のように書き換えておきます。

- canContain(&$obj) を全て canContain($obj) とする。約10箇所。
- {0}は全て[0]にする。3箇所。
- 181,187,915行目の#を!へ変更する（※915行目の'!' => 'Div');は変えずに'#' => 'Div');のままでもよいかもしれない）

### lib/file.php[ ](https://oncologynote.com/?2a74686299#y8a442fd)

- \#freezeの2箇所を!freezeにする
- 257,258,279,280,545,610行目の#を!へ変更する（169,191行目は変えなくてもよいかもしれない。pukiwiki1.5.0.-mdでも変えずに動作しているよう）

### lib/make_link.phpの修正[ ](https://oncologynote.com/?2a74686299#jac9d27b)

123行目付近の下記の行をコメントアウトして書き換えます。

```
// $arr = explode("\x08", make_line_rules(htmlsc($string)));
$arr = explode("\x08", make_line_rules($string));
```

htmlscは、htmlspecialcharsで特殊文字をHTMLエンティティに変換するための内部コードですが、HTMLエンティティに変換してしまうと正しくパースされない部分があります。
 また、{0}も1箇所[0]に修正しておく。

78〜93行目のうち下記の行をコメントアウトしておく。

```
if ($converters === NULL) {
	$converters = array(
		'plugin',        // Inline plugins
		'note',          // Footnotes
		// 'url',           // URLs
		// 'url_interwiki', // URLs (interwiki definition)
		// 'mailto',        // mailto: URL schemes
		// 'interwikiname', // InterWikiNames
		// 'autoalias',     // AutoAlias
		// 'autolink',      // AutoLinks
		'bracketname',   // BracketNames
		// 'wikiname',      // WikiNames
		// 'autoalias_a',   // AutoAlias(alphabet)
		// 'autolink_a',    // AutoLinks(alphabet)
	);
}
```

### #authorの修正[ ](https://oncologynote.com/?2a74686299#u931b205)

下記のファイルで #author となっている部分を全て !author にする必要がある。

- lib/backup.php
- lib/file.php

### 現状の問題点[ ](https://oncologynote.com/?2a74686299#b44e6b32)

- MULTILINEプラグインが正しく引数を読み込めない
  - おそらくlib/convert_html.phpに書き加えた31行目付近の if ( preg_match('/^\\!([a-zA-Z0-9_]+)(\\(([^\\)\\n]*)?\\))?/', $line,  $matches) ) という部分の正規表現に問題があるが、どのように修正すれば良いのか未解決。
  - html.inc.phpのようなMULTILINEが必須のプラグインが動作しないが、markdownはある程度はRAW HTMLが書けるので簡単なHTMLならプラグイン無しで対応できそう。
- [[リンク]]という形式の内部リンクは正しく解釈されるが、[[リンクテキスト>リンクURL]]あるいは[[リンクテキスト:リンクURL]]というPukiwiki式の外部リンクは正しく反映されない。
  - [リンクテキスト] (リンクURL) というMarkdown式の書き方にする必要がある。
- 個々のプラグインまでは動作検証できていません。
- 改行を反映するかどうかはlib/convert_html.phpの55行目付近で設定変更できる。
  - $result = $parsedown->text($text); とすれば行末に半角スペース2つで改行されるオリジナルのMarkdown文法となる。
  - $result = $parsedown ->setBreaksEnabled(true) ->text($text); とすれば改行は自動で反映される。
  - その他のMarkdown parserに関する設定についてはhttps://github.com/erusev/parsedown/wiki/ のREADMEおよびtutorialに解説されている。
