<?php
/*
* [概要]
*  HTML ファイルを取得して<Component />のタグをすべて取得し,
*  file=""に指定しているファイルを再帰的に取得する.
*  取得しながらオプションで指定している値の置換を行います.
*  コンポーネント指向の簡易テンプレートエンジン。
*
* [できること]
*  1. 最初のファイルから<Component />をもとに子コンポーネントを辿る.
*  2. 値(Reactならprops)を子コンポーネントに渡せる.
*
* [課題]
*  1.
*   id={'123456}'} というオプションを正規表現で抽出する時,
*   id={'123456}'が抽出されてしまうので, 時間があれば改善したい.
*  2. 多次元配列, 連想配列を子コンポーネントに渡せるようにしたい
*  3. 多次元配列, 連想配列のループ処理を行いたい
*/

function TemplateEngine(string $filePath) {
  // ファイルが存在しない場合は, 空文字を返却
  if (!file_exists($filePath)) {
    return '';
  }

  // ファイルの中身を取得
  $html = file_get_contents($filePath);
  $html = mb_convert_encoding($html, 'UTF-8');

  /*
  * 指定したファイルのパスからディレクトリのパスを抽出
  * コンポーネントで指定しているファイルのパスが相対の場合,
  * これを基準にする.
  */
  $dirs = array_slice(explode('/', $filePath), 0, -1);
  $dir = join('/', $dirs);

  //  コンポーネントのタグを抽出
  $pattern = '/<Component.*?\/>/s';
  preg_match_all($pattern, $html, $componentTags);
  $componentTags = $componentTags[0];

  // コンポーネントタグがない場合はそのまま HTML を返却
  if (count($componentTags) === 0) return $html;

  // コンポーネントタグを 1つずつ解析
  foreach($componentTags as $original_tag) {
    /*
    * オプションを解析して取得.
    * 値が空のオプションは含まない.
    */
    $options = ParseOptions($original_tag);

    /*
    * ファイル指定のオプションが有効でない場合は,
    * 該当のコンポーネントタグを削除して終了.
    */
    if (!isset($options['file'])) {
      $html = str_replace($original_tag, '', $html);
      continue;
    }

    // ファイルのパスが相対か絶対か
    $componentFilePath = substr($options['file'], 0, 1) === '/'
      ? $options['file'] // 絶対ならそのまま
      : "$dir/{$options['file']}";

    /*
    * 指定しているファイルが存在しない場合も,
    * 該当のコンポーネントタグを削除して終了.
    */
    if (!file_exists($componentFilePath)) {
      $html = str_replace($original_tag, '', $html);
      continue;
    }

    // 指定しているファイルの中身を取得
    $component = file_get_contents($componentFilePath);

    // コンポーネントの中のコンポーネントタグを探索する
    $component = TemplateEngine($componentFilePath, $component);

    /*
    * オプションを見て, 該当のタグを置換する.
    * id='12345' と指定してある時は,
    * 呼び出したコンポーネントの %id% を 12345 へ置換する
    */
    foreach($options as $key => $value) {
      $key = "%$key%";

      if (is_Array($value)) { // 値が配列の時
        $component = ReplaceForeachComment($key, $value, $component);
      } else { // 値が文字列または数値の時
        $component = str_replace($key, "$value", $component);
      }
    }

    /*
    * 最後にタグを置換したコンポーネントを,
    * コンポーネントタグに置換して挿入.
    */
    $html = str_replace($original_tag, $component, $html);
  }

  return $html;
}

function ParseOptions(string $original_tag) {
  /*
  * 改行をいったん半角スペースに置換し, 1行にする.
  * 改行のある/なし両方の書き方に対応するため.
  */
  $tag = str_replace(PHP_EOL, ' ', $original_tag);

  // 最初についている '<Component' の文字列を削除
  if (substr($tag, 0, 10) === '<Component') {
    $tag = substr($tag, 10);
  }

  // 最後についている '/>' の文字列を削除
  if (substr($tag, -2) === '/>') {
    $tag = substr($tag, 0, -2);
  }

  /*
  * オプションを正規表現で抽出.
  * '={'と'}'の最短一致のパターン.
  */
  preg_match_all('/.*?={.*?}/', $tag, $options);
  $options = $options[0];

  // 余計なスペースと'{' や'}' を削除し, オプションを'='で分割
  $options = array_map(function($s) {
    $s = trim($s);

    /*
    * '=' が位置を探す.
    * explode を使うと値の中に'='があった場合に分割してしまう.
    */
    $equal_symbol = strpos($s, '=');
    $result = [
      substr($s, 0, $equal_symbol),
      substr($s, $equal_symbol + 1)
    ];

    // '{' と '}' を削除
    $result[1] = substr($result[1], 1, -1);

    return $result;
  }, $options);

  /*
  * 多次元配列を連想配列へ変換.
  * 変換前: [['a', 'i'], ['u', 'e']]
  * 変換後: [['a' => 'i'], ['u' => 'e']]
  * array_columns(配列, valueにする値, keyにする値)
  */
  $options = array_column($options, 1, 0);

  /*
  * 値の前後にダブル/シングルクォートと半角スペースがあれば削除.
  * クォートを許可しているのは属性の指定を見やすくするためだけなので.
  */
  $options = array_map(function($s) {
    return trim($s, ' "\'');
  }, $options);

  // 値が空のオプションは削除
  $options = array_filter($options, 'boolval');

  /*
  * 値が配列を指定している場合は,
  * 文字列からちゃんとしたPHPの配列を作る
  */
  $options = array_map(function($value) {
    $firstWord = substr($value, 0, 1);
    $lastWord = substr($value, -1);

    if ($firstWord === '[' && $lastWord === ']') {
      $pattern = '/(".*?"|\'.*?\'|[0-9]{1,})/s';
      preg_match_all($pattern, $value, $values);
      $values = $values[0];

      // ダブル/シングルクォートと半角スペースがあれば削除
      $value = array_map(function($v) {
        return trim($v, ' "\'');
      }, $values);
    }

    return $value;
  }, $options);

  return $options;
}

function ReplaceForeachComment(
  string $tag, // 例: %items%
  array $values,
  string $html
) {
  $start = "<!--.*?foreach.*?$tag.*?-->";
  $end = "<!--.*?endforeach.*?$tag.*?-->";
  $pattern =
    "/$start.*?$end/s";

  // ループ処理を示すコメントの記述を抽出
  preg_match_all($pattern, $html, $foreachTags);
  $foreachTags = $foreachTags[0];

  // ループタグが見つからなかったらそのまま返却
  if (count($foreachTags) === 0) return $html;

  // ループタグを1つずつ処理
  foreach($foreachTags as $original_foreachTag) {
    $replacedHtml = [];

    // もう使わないので, ループタグを削除
    $foreachTag = preg_replace(
      "/($start|$end)/",
      '',
      $original_foreachTag
    );

    // 値の配列を順次に置換
    foreach($values as $value) {
      $replacedHtml[] = str_replace($tag, $value, $foreachTag);
    }

    // 置換
    $html = str_replace(
      $original_foreachTag,
      join(PHP_EOL, $replacedHtml),
      $html
    );
  }

  return $html;
}
