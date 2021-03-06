<?php
class Soar {

  private $config = [
    'report-type' => 'json',
    'allow-online-as-test' => 'true',
    'sampling' => 'false',
  ];
  private $cmd = '';

  public function __construct($config = []) {
    $this->config($config);
    $this->cmd = $this->getCmd();
    $this->cmd .= ' ' . $this->buildConfig();
  }

  /**
   * @return string cmd
   */
  private function getCmd() {
    defined('PHP_OS') or define('PHP_OS', 'Linux');
    if (DIRECTORY_SEPARATOR == '\\') {
      $cmd = 'soar.windows-amd64.exe';
    } else {
      $cmd = stristr(PHP_OS, 'darwin') ? 'soar.darwin-amd64' : 'soar.linux-amd64';
    }
    return __DIR__ . '/bin/' . $cmd;
  }

  /**
   * 设置soar 配置
   * @param array $config
   */
  public function config($config) {
    $this->config = array_merge($this->config, $config);
  }

  private function buildConfig() {
    $options = [];
    foreach ($this->config as $key => $val) {
      $options[] = "-{$key}={$val}";
    }
    return implode(' ', $options);
  }

  /**
   * return array
   */
  public function analysis($sql) {
    $sql = trim(preg_replace('/^explain/i', '', trim($sql)));
    $f = proc_open($this->cmd, [
      ['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']
    ], $pipes);
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $data = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return $this->config['report-type'] == 'json' ? (json_decode($data, true) ?: [[]]) : $data;
  }
}

/**
 * format array to html for phpmyadmin
 */
class SoarHtml {

  private $r;
  
  private $config;
  
  private $columns = [
    'Item', 'Level', 'Summary', 'Content', 'Case'
  ];

  public function __construct($arr) {
    $this->r = $arr;
    $this->parseResult();
  }

  /**
   * 解析结, 设置分数和 explain解读, 并按照level排序
   */
  private function parseResult() {
    $total = 100;
    $explainItem = [];
    $analysis = [];
    foreach ($this->r as $key => $val) {
      $num = intval(str_replace(['L', 'l'], '', $val['Severity']));
      $total -= $num * 5;
      if (strpos($key, 'EXP') !== false) {
        $explainItem = $val;
      } else {
        $val['Level'] = $num;
        $analysis[] = $val;
      }
    }
    usort($analysis, function ($a, $b) {
      return $b['Level'] - $a['Level'];
    });
    $this->config['num'] = $total < 0 ? 0 : $total;
    $this->config['explain'] = $explainItem;
    $this->config['analysis'] = $analysis;
  }

  /**
   * 分数html
   */
  public function asNumHtml() {
    $margin = '20px 0px 0px 0px';
    $verArr = explode('.', PMA_VERSION);
    $ver = "{$verArr[0]}.{$verArr[1]}";
    switch ($ver) {
      case '4.8';
        $margin = '20px 0px 10px 0px';
        break;
      case '4.7';
      case '4.6';
        $margin = '20px 0px 0px 0px';
        break;
      case '4.5';
      case '4.4';
      case '4.3';
      case '4.2';
      case '4.1';
      case '4.0';
        $margin = '0px 0px 0px 0px';
        break;
    }
    return "<h3 style=\"margin:{$margin}\">评分：{$this->config['num']}分</h3>";
  }

  /**
   * expalin html
   */
  public function asExplainHtml() {
    if ($this->config['explain']) {
      $html = $this->config['explain']['Case'];
      $html = preg_replace('/####(.+?)\n/', '<h4 style="margin:5px 20px;">$1</h4>', $html);
      $html = preg_replace('/###(.+?)\n/', '<h3 style="margin:10px 0px;">$1：</h3>', $html);
      $html = preg_replace('/\* (.+?)\n/', '<ul style="margin:0px;">$1</ul>', $html);
      $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
      $html = "<div style=\"margin-bottom:20px;\">{$html}</div>";
      return $html;
    }
    return '';
  }

  /**
   * sql 建议 html
   */
  public function asItemHtml() {
    $html = '';
    if ($this->config['analysis']) {
      $html .= '<h3 style="margin:10px 0px;">SQL建议与优化：</h3>';
      $html .= '<table class="table_results ajax pma_table" data-uniqueid="18066"><thead><tr>';
      foreach ($this->columns as $column) {
        $html .= '<th class="draggable"><span>' . $column . '</span></th>';
      }
      $html .= '<td class="print_ignore"><span></span></td></tr></thead><tbody>';
      foreach ($this->config['analysis'] as $index => $item) {
        $class = ($index % 2) ? 'even' : 'odd';
        $html .= '<tr class="' . $class . '">';
        foreach ($this->columns as $column) {
          $html .= "<td data-decimals=\"0\" data-type=\"string\" class=\"data text\"><span>{$item[$column]}</span></td>";
        }
        $html .= '</tr>';
      }
    }
    $html .= '</tbody></table>';
    return $html;
  }
}

/**
 * 检测是否有phpmyadmin 环境
 */
if (defined('PMA_VERSION')) {
  $db = $GLOBALS['db'];
  global $cfg;
  $host = $cfg['Server']['host'];
  $user = $cfg['Server']['user'];
  $pwd = $cfg['Server']['password'];
  $port = $cfg['Server']['port'] ?: '3306';
  //如果密码含特殊字符, 则读取配置文件
  if (preg_match('/[@:\/]/', $pwd)) {
    $file = __DIR__. '/bin/soar.yaml';
    $content = "test-dsn:\n  addr: '{$host}:{$port}'\n  schema: '{$db}'\n  user: '{$user}'\n  password: '{$pwd}'\n  disable: false";
    file_put_contents($file, $content);
    $soar = new Soar();
  } else {
    $dsn = "{$user}:{$pwd}@{$host}:{$port}/$db";
    $soar = new Soar(['test-dsn' => $dsn]);
  }
  $GLOBALS['soar'] = $soar;

  /**
   * 格式化sql
   */
  function get_analyzed_sql($sqlParser) {
    $sql = '';
    foreach ($sqlParser->tokens as $val) {
      if (!is_null($val)) $sql .= $val->token;
    }
    return $sql;
  }
}

##################  demo  ###################
//$soar = new Soar(['test-dsn' => 'xxx']);
//$r = $soar->analysis($sql);
//print_r($r);