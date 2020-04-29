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
      $component = str_replace($key, "$value", $component);
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

  // 最後についている '/>' の文字列を削除
  if (substr($tag, -2) === '/>') {
    $tag = substr($tag, 0, -2);
  }

  // 半角スペースで分割
  $information = explode(' ', $tag);

  /*** オプションの抽出 ***/
  // オプションの形をしている文字列('='の記号がある)のみを抽出
  $options = array_filter($information, function($s) {
    return (strpos($s, '=') !== false);
  });

  // オプションを'='で分割し, 対応する配列を作成
  $options = array_map(function($s) {
    return explode('=', $s);
  }, $options);

  /*
  * 多次元配列を連想配列へ変換.
  * 変換前: [['a', 'i'], ['u', 'e']]
  * 変換後: [['a' => 'i'], ['u' => 'e']]
  * array_columns(配列, valueにする値, keyにする値)
  */
  $options = array_column($options, 1, 0);

  /*
  * 値の前後にクォートがついている場合は削除する.
  * クォートを許可しているのは属性の指定を見やすくするためだけなので.
  */
  $options = array_map(function($s) {
    $firstWord = substr($s, 0, 1);
    $lastWord = substr($s, -1);

    // 最初の文字を比較
    if ($firstWord === "'" || $firstWord === '"') {
      $s = substr($s, 1);
    }

    // 最後の文字を比較
    if ($lastWord === "'" || $lastWord === '"') {
      $s = substr($s, 0, -1);
    }

    return $s;
  }, $options);

  // 値が空のオプションは削除
  $options = array_filter($options, 'boolval');

  return $options;
}
