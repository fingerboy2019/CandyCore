<?php

declare(strict_types=1);

namespace Catlog\XhprofLib\Utils;

use \Catlog\XhprofLib\Display\XhprofDisplay;

/**
 * 调用图生成类
 * 用于生成和处理XHProf性能分析数据的调用图
 *
 * @author System
 * @version 1.0
 * @since 2026-01-16
 */
class CallGraph
{
  /**
   * 支持的图像输出格式
   * @var array 键为格式名称，值为1
   */
  public static $xhprof_legal_image_types = [
    "jpg" => 1,
    "gif" => 1,
    "png" => 1,
    "svg" => 1, // 支持可缩放矢量图形
    "ps"  => 1,
  ];

  /**
   * 发送HTTP头信息
   * 
   * @param string $name HTTP头名称，如'Location'
   * @param string $value HTTP头值，如'http://www.example.com/'
   * @return void
   */
  public static function xhprof_http_header($name, $value)
  {

    if (!$name) {
      XhprofLib::xhprof_error('http_header usage');
      return null;
    }
    if (!is_string($value)) XhprofLib::xhprof_error('http_header value not a string');
    header($name . ': ' . $value, true);
  }

  /**
   * 生成并发送图像输出的MIME头信息
   * 
   * @param string $type 图像类型
   * @param int $length 图像长度
   * @return void
   */
  public static function xhprof_generate_mime_header($type, $length)
  {
    $mime = match ($type) {
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'png' => 'image/png',
        'svg' => 'image/svg+xml', // content type for scalable vector graphic
        'ps' => false, // NOTE: original code had bug where 'ps' case falls-through to default
        default => false,
    };
    if ($mime) {
      self::xhprof_http_header('Content-type', $mime);
      self::xhprof_http_header('Content-length', (string)$length);
    }
  }

  /**
   * 使用dot脚本生成图像
   * 通过调用graphviz的dot命令将dot脚本转换为指定类型的图像
   * 
   * @param string $dot_script dot脚本内容
   * @param string $type 输出图像类型
   * @return string|void 生成的图像数据或null（如果失败）
   */
  public static function xhprof_generate_image_by_dot($dot_script, $type)
  {
    $descriptorspec = [
      // 标准输入是子进程将读取的管道
      0 => ["pipe", "r"],
      // 标准输出是子进程将写入的管道
      1 => ["pipe", "w"],
      // 标准错误是子进程将写入的管道
      2 => ["pipe", "w"]
    ];

    $cmd = " dot -T" . $type;

    $process = proc_open($cmd, $descriptorspec, $pipes, "/tmp", []);
    if (is_resource($process)) {
      fwrite($pipes[0], $dot_script);
      fclose($pipes[0]);

      $output = stream_get_contents($pipes[1]);
      $err = stream_get_contents($pipes[2]);
      if (!empty($err)) {
        \Candy\Core\Log::input("failed to execute cmd: \"$cmd\". stderr: `$err'\n");
        return;
      }

      fclose($pipes[2]);
      fclose($pipes[1]);
      proc_close($process);
      return $output;
    }
    \Candy\Core\Log::input("failed to execute cmd \"$cmd\"");
    return;
  }

  /**
   * 获取所有节点的子节点列表
   * 
   * @param array $raw_data 原始性能分析数据
   * @return array 子节点表，键为父节点，值为子节点数组
   */
  public static function xhprof_get_children_table($raw_data)
  {
    $children_table = [];
    foreach ($raw_data as $parent_child => $info) {
      [$parent, $child] = XhprofLib::xhprof_parse_parent_child($parent_child);
      if (!isset($children_table[$parent])) {
        $children_table[$parent] = [$child];
      } else {
        $children_table[$parent][] = $child;
      }
    }
    return $children_table;
  }


  /**
   * 生成dot脚本用于绘制调用图
   * 
   * @param array $raw_data 原始性能分析数据
   * @param mixed $threshold 阈值
   * @param mixed $source 数据源
   * @param mixed $page 页面参数
   * @param mixed $func 函数名
   * @param mixed $critical_path 关键路径
   * @param mixed $right 右侧参数
   * @param mixed $left 左侧参数
   * @return string dot脚本内容
   */
  public static function xhprof_generate_dot_script(
    $raw_data,
    $threshold,
    $source,
    $page,
    $func,
    $critical_path,
    $right = null,
    $left = null
  ) {

    $max_width = 5;
    $max_height = 3.5;
    $max_fontsize = 35;
    $max_sizing_ratio = 20;
    
    if (isset($totals_callback) && is_callable($totals_callback)) {
        XhprofDisplay::$totals = call_user_func($totals_callback);
    } else {
        XhprofDisplay::$totals = [];
    }

    $sym_table = XhprofLib::xhprof_compute_flat_info($raw_data, $totals_callback);
    if ($critical_path) {
      $children_table = self::xhprof_get_children_table($raw_data);
      $node = "main()";
      $path = [];
      $path_edges = [];
      $visited = [];
      while ($node) {
        $visited[$node] = true;
        if (isset($children_table[$node])) {
          $max_child = null;
          foreach ($children_table[$node] as $child) {

            if (isset($visited[$child])) continue;
            if (
              $max_child === null ||
              abs($raw_data[XhprofLib::xhprof_build_parent_child_key(
                $node,
                $child
              )]["wt"]) >
              abs($raw_data[XhprofLib::xhprof_build_parent_child_key(
                $node,
                $max_child
              )]["wt"])
            ) {
              $max_child = $child;
            }
          }
          if ($max_child !== null) {
            $path[$max_child] = true;
            $path_edges[XhprofLib::xhprof_build_parent_child_key($node, $max_child)] = true;
          }
          $node = $max_child;
        } else {
          $node = null;
        }
      }
    }

    // if it is a benchmark callgraph, we make the benchmarked public static function the root.
    if ($source == "bm" && array_key_exists("main()", $sym_table)) {
      $total_times = $sym_table["main()"]["ct"];
      $remove_funcs = [
        "main()",
        "hotprofiler_disable",
        "call_user_func_array",
        "xhprof_disable"
      ];

      foreach ($remove_funcs as $cur_del_func) {
        if (
          array_key_exists($cur_del_func, $sym_table) &&
          $sym_table[$cur_del_func]["ct"] == $total_times
        ) {
          unset($sym_table[$cur_del_func]);
        }
      }
    }

    // use the public static function to filter out irrelevant public static functions.
    if (!empty($func)) {
      $interested_funcs = [];
      foreach ($raw_data as $parent_child => $info) {
        [$parent, $child] = XhprofLib::xhprof_parse_parent_child($parent_child);
        if ($parent == $func || $child == $func) {
          $interested_funcs[$parent] = 1;
          $interested_funcs[$child] = 1;
        }
      }
      foreach ($sym_table as $symbol => $info) {
        if (!array_key_exists($symbol, $interested_funcs)) unset($sym_table[$symbol]);
      }
    }

    $result = "digraph call_graph {\n";
    $cur_id = 0;
    $max_wt = 0;
    foreach ($sym_table as $symbol => $info) {
      if (empty($func) && abs($info["wt"] / XhprofDisplay::$totals["wt"]) < $threshold) {
        unset($sym_table[$symbol]);
        continue;
      }
      if ($max_wt == 0 || $max_wt < abs($info["excl_wt"])) $max_wt = abs($info["excl_wt"]);
      $sym_table[$symbol]["id"] = $cur_id;
      $cur_id++;
    }

    // Generate all nodes' information.
    foreach ($sym_table as $symbol => $info) {
      if ($info["excl_wt"] == 0) {
        $sizing_factor = $max_sizing_ratio;
      } else {
        $sizing_factor = $max_wt / abs($info["excl_wt"]);
        if ($sizing_factor > $max_sizing_ratio) $sizing_factor = $max_sizing_ratio;
      }
      $fillcolor = (($sizing_factor < 1.5) ?
        ", style=filled, fillcolor=red" : "");

      if ($critical_path && !$fillcolor && array_key_exists($symbol, $path)) $fillcolor = ", style=filled, fillcolor=yellow";

      $fontsize = ", fontsize=" . (int)($max_fontsize / (($sizing_factor - 1) / 10 + 1));
      $width = ", width=" . sprintf("%.1f", $max_width / $sizing_factor);
      $height = ", height=" . sprintf("%.1f", $max_height / $sizing_factor);
      $shape = "box";
      $name = addslashes($symbol) . "\\nInc: " . sprintf("%.3f", $info["wt"] / 1000) .
        " ms (" . sprintf("%.1f%%", 100 * $info["wt"] / XhprofDisplay::$totals["wt"]) . ")";
      if ($symbol == "main()") {
        $shape = "octagon";
        $name = "Total: " . (XhprofDisplay::$totals["wt"] / 1000.0) . " ms\\n";
        $name .= addslashes(isset($page) ? $page : $symbol);
      }
      if ($left === null) {
        $label = ", label=\"" . $name . "\\nExcl: "
          . (sprintf("%.3f", $info["excl_wt"] / 1000.0)) . " ms ("
          . sprintf("%.1f%%", 100 * $info["excl_wt"] / XhprofDisplay::$totals["wt"])
          . ")\\n" . $info["ct"] . " total calls\"";
      } else {
        if (isset($left[$symbol]) && isset($right[$symbol])) {
          $label = ", label=\"" . addslashes($symbol) .
            "\\nInc: " . (sprintf("%.3f", $left[$symbol]["wt"] / 1000.0))
            . " ms - "
            . (sprintf("%.3f", $right[$symbol]["wt"] / 1000.0)) . " ms = "
            . (sprintf("%.3f", $info["wt"] / 1000.0)) . " ms" .
            "\\nExcl: "
            . (sprintf("%.3f", $left[$symbol]["excl_wt"] / 1000.0))
            . " ms - " . (sprintf("%.3f", $right[$symbol]["excl_wt"] / 1000.0))
            . " ms = " . (sprintf("%.3f", $info["excl_wt"] / 1000.0)) . " ms" .
            "\\nCalls: " . (sprintf("%.3f", $left[$symbol]["ct"])) . " - "
            . (sprintf("%.3f", $right[$symbol]["ct"])) . " = "
            . (sprintf("%.3f", $info["ct"])) . "\"";
        } else if (isset($left[$symbol])) {
          $label = ", label=\"" . addslashes($symbol) .
            "\\nInc: " . (sprintf("%.3f", $left[$symbol]["wt"] / 1000.0))
            . " ms - 0 ms = " . (sprintf("%.3f", $info["wt"] / 1000.0))
            . " ms" . "\\nExcl: "
            . (sprintf("%.3f", $left[$symbol]["excl_wt"] / 1000.0))
            . " ms - 0 ms = "
            . (sprintf("%.3f", $info["excl_wt"] / 1000.0)) . " ms" .
            "\\nCalls: " . (sprintf("%.3f", $left[$symbol]["ct"])) . " - 0 = "
            . (sprintf("%.3f", $info["ct"])) . "\"";
        } else {
          $label = ", label=\"" . addslashes($symbol) .
            "\\nInc: 0 ms - "
            . (sprintf("%.3f", $right[$symbol]["wt"] / 1000.0))
            . " ms = " . (sprintf("%.3f", $info["wt"] / 1000.0)) . " ms" .
            "\\nExcl: 0 ms - "
            . (sprintf("%.3f", $right[$symbol]["excl_wt"] / 1000.0))
            . " ms = " . (sprintf("%.3f", $info["excl_wt"] / 1000.0)) . " ms" .
            "\\nCalls: 0 - " . (sprintf("%.3f", $right[$symbol]["ct"]))
            . " = " . (sprintf("%.3f", $info["ct"])) . "\"";
        }
      }
      $result .= "N" . $sym_table[$symbol]["id"];
      $result .= "[shape=$shape " . $label . $width . $height . $fontsize . $fillcolor . "];\n";
    }

    // Generate all the edges' information.
    foreach ($raw_data as $parent_child => $info) {
      [$parent, $child] = XhprofLib::xhprof_parse_parent_child($parent_child);

      if (
        isset($sym_table[$parent]) && isset($sym_table[$child]) &&
        (empty($func) ||
          (!empty($func) && ($parent == $func || $child == $func)))
      ) {

        $label = $info["ct"] == 1 ? $info["ct"] . " call" : $info["ct"] . " calls";

        $headlabel = $sym_table[$child]["wt"] > 0 ?
          sprintf("%.1f%%", 100 * $info["wt"]
            / $sym_table[$child]["wt"])
          : "0.0%";

        $taillabel = ($sym_table[$parent]["wt"] > 0) ?
          sprintf(
            "%.1f%%",
            100 * $info["wt"] /
              ($sym_table[$parent]["wt"] - $sym_table["$parent"]["excl_wt"])
          )
          : "0.0%";

        $linewidth = 1;
        $arrow_size = 1;

        if (
          $critical_path &&
          isset($path_edges[XhprofLib::xhprof_build_parent_child_key($parent, $child)])
        ) {
          $linewidth = 10;
          $arrow_size = 2;
        }

        $result .= "N" . $sym_table[$parent]["id"] . " -> N"
          . $sym_table[$child]["id"];
        $result .= "[arrowsize=$arrow_size, color=grey, style=\"setlinewidth($linewidth)\","
          . " label=\""
          . $label . "\", headlabel=\"" . $headlabel
          . "\", taillabel=\"" . $taillabel . "\" ]";
        $result .= ";\n";
      }
    }
    $result = $result . "\n}";
    return $result;
  }
  /**
   * 渲染差异图像
   * 生成并返回差异图像的dot脚本内容
   * 
   * @param string $type 图像类型
   * @param mixed $threshold 阈值
   * @param mixed $source 数据源
   * @return string 图像数据或null（如果失败）
   */
  public static function  xhprof_render_diff_image(
    $run1,
    $run2,
    $type,
    $threshold,
    $source
  ) {
    $total1=0;
    $total2=0;
    $raw_data1 = XHProfRunsDefault::get_run($run1, $source, $desc_unused);
    $raw_data2 = XHProfRunsDefault::get_run($run2, $source, $desc_unused);
    $symbol_tab1 = XhprofLib::xhprof_compute_flat_info($raw_data1, $total1);
    $symbol_tab2 = XhprofLib::xhprof_compute_flat_info($raw_data2, $total2);
    $run_delta = XhprofLib::xhprof_compute_diff($raw_data1, $raw_data2);
    $script = self::xhprof_generate_dot_script($run_delta, $threshold, $source, null, null, true, $symbol_tab1, $symbol_tab2);
    $content = self::xhprof_generate_image_by_dot($script, $type);
    self::xhprof_generate_mime_header($type, strlen($content));
    return $content;
  }
  /**
   * 获取性能分析运行数据的图像内容
   * 从性能分析运行数据中生成并返回图像内容
   * 
   * @param string $type 图像类型
   * @param mixed $threshold 阈值
   * @param mixed $func 函数名
   * @param mixed $source 数据源
   * @param mixed $critical_path 关键路径
   * @return string 图像数据或null（如果失败）
   */
  public static function xhprof_get_content_by_run(
    $run_id,
    $type,
    $threshold,
    $func,
    $source,
    $critical_path
  ) {
    if (!$run_id) return "";
    $raw_data = XHProfRunsDefault::get_run($run_id, $source, $description);
    if (!$raw_data) {
      XhprofLib::xhprof_error("Raw data is empty");
      return "";
    }
    $script = self::xhprof_generate_dot_script(
      $raw_data,
      $threshold,
      $source,
      $description,
      $func,
      $critical_path
    );
    $content = self::xhprof_generate_image_by_dot($script, $type);
    return $content;
  }
  
  /**
   * 渲染图像
   * 生成并返回性能分析运行数据的图像内容
   * 
   * @param string $type 图像类型
   * @param mixed $threshold 阈值
   * @param mixed $func 函数名
   * @param mixed $source 数据源
   * @param mixed $critical_path 关键路径
   * @return string 图像数据或null（如果失败）
   */
  public static function xhprof_render_image(
    $run_id,
    $type,
    $threshold,
    $func,
    $source,
    $critical_path
  ) {

    $content = self::xhprof_get_content_by_run(
      $run_id,
      $type,
      $threshold,
      $func,
      $source,
      $critical_path
    );
    if (!$content) {
      \Candy\Core\Log::input("Error: either we can not find profile data for run_id " . $run_id
        . " or the threshold " . $threshold . " is too small or you do not"
        . " have 'dot' image generation utility installed.");
      return;
    }

    self::xhprof_generate_mime_header($type, strlen($content));
    return $content;
  }
}
