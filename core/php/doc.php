<?php
/*
 * Documentation locale du plugin : affiche docs/<langue>/<page>.md en HTML (le .htaccess de Jeedom interdit /docs/ au navigateur) (boutons Documentation et Changelog
 * de la page plugin de Jeedom). Réservé aux utilisateurs connectés à Jeedom.
 */
require_once __DIR__ . '/../../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    header('Location: /index.php');
    exit;
}

$plugin = basename(dirname(__DIR__, 2));
$docs = __DIR__ . '/../../docs';
$page = in_array(@$_GET['page'], array('index', 'changelog')) ? $_GET['page'] : 'index';
$lang = preg_match('/^[a-z]{2}_[A-Z]{2}$/', (string) @$_GET['lang']) ? $_GET['lang'] : 'fr_FR';
$file = $docs . '/' . $lang . '/' . $page . '.md';
if (!file_exists($file)) {
    $file = $docs . '/fr_FR/' . $page . '.md';
}
$md = file_exists($file) ? file_get_contents($file) : "# Page introuvable\n";
$info = json_decode((string) @file_get_contents(__DIR__ . '/../../plugin_info/info.json'), true);
$name = isset($info['name']) ? $info['name'] : $plugin;
$version = isset($info['pluginVersion']) ? $info['pluginVersion'] : '';

/** Markdown simple : titres, listes, tableaux, code, gras, italique, liens, citations. */
function docInline($t) {
    $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $codes = array();
    $t = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
        $codes[] = '<code>' . $m[1] . '</code>';
        return "\x01" . (count($codes) - 1) . "\x01";
    }, $t);
    $t = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
    $t = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $t);
    $t = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '<a href="$2">$1</a>', $t);
    return preg_replace_callback("/\x01(\d+)\x01/", function ($m) use ($codes) {
        return $codes[(int) $m[1]];
    }, $t);
}

function docSlug($t) {
    $t = strtolower(trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', $t), '-'));
    return $t === '' ? 'section' : $t;
}

function docRender($md, &$toc) {
    $lines = preg_split('/\r?\n/', $md);
    $out = '';
    $para = array();
    $list = null;
    $flush = function () use (&$para, &$out) {
        if (count($para)) {
            $out .= '<p>' . docInline(implode(' ', $para)) . "</p>\n";
            $para = array();
        }
    };
    $closeList = function () use (&$list, &$out) {
        if ($list !== null) {
            $out .= "</$list>\n";
            $list = null;
        }
    };
    for ($i = 0; $i < count($lines); $i++) {
        $l = $lines[$i];
        if (preg_match('/^```/', $l)) {
            $flush();
            $closeList();
            $code = array();
            while (++$i < count($lines) && !preg_match('/^```/', $lines[$i])) {
                $code[] = $lines[$i];
            }
            $out .= '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . "</code></pre>\n";
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.*)$/', $l, $m)) {
            $flush();
            $closeList();
            $n = strlen($m[1]);
            $id = docSlug($m[2]);
            if ($n == 2) {
                $toc[] = array($id, $m[2]);
            }
            $out .= "<h$n id=\"$id\">" . docInline($m[2]) . "</h$n>\n";
            continue;
        }
        if (preg_match('/^\s*\|.*\|\s*$/', $l) && isset($lines[$i + 1]) && preg_match('/^\s*\|[\s:|-]+\|\s*$/', $lines[$i + 1])) {
            $flush();
            $closeList();
            $cells = function ($r) {
                return array_map('trim', explode('|', trim(trim($r), '|')));
            };
            $out .= "<table><thead><tr>";
            foreach ($cells($l) as $c) {
                $out .= '<th>' . docInline($c) . '</th>';
            }
            $out .= "</tr></thead><tbody>\n";
            $i++;
            while (isset($lines[$i + 1]) && preg_match('/^\s*\|.*\|\s*$/', $lines[$i + 1])) {
                $i++;
                $out .= '<tr>';
                foreach ($cells($lines[$i]) as $c) {
                    $out .= '<td>' . docInline($c) . '</td>';
                }
                $out .= "</tr>\n";
            }
            $out .= "</tbody></table>\n";
            continue;
        }
        if (preg_match('/^(\s*)([*-]|\d+\.)\s+(.*)$/', $l, $m)) {
            $flush();
            $type = ctype_digit($m[2][0]) ? 'ol' : 'ul';
            if ($list !== $type) {
                $closeList();
                $out .= "<$type>\n";
                $list = $type;
            }
            $item = $m[3];
            while (isset($lines[$i + 1]) && preg_match('/^\s{2,}\S/', $lines[$i + 1]) && !preg_match('/^\s*([*-]|\d+\.)\s/', $lines[$i + 1])) {
                $item .= ' ' . trim($lines[++$i]);
            }
            $out .= '<li>' . docInline($item) . "</li>\n";
            continue;
        }
        if (preg_match('/^>\s?(.*)$/', $l, $m)) {
            $flush();
            $closeList();
            $out .= '<blockquote>' . docInline($m[1]) . "</blockquote>\n";
            continue;
        }
        if (trim($l) === '') {
            $flush();
            $closeList();
            continue;
        }
        $closeList();
        $para[] = trim($l);
    }
    $flush();
    $closeList();
    return $out;
}

$toc = array();
$body = docRender($md, $toc);
$self = 'doc.php?lang=' . $lang . '&page=';
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($name . ' · ' . ($page == 'changelog' ? 'Changelog' : 'Documentation')); ?></title>
<style>
:root { --bg: #f6f7f9; --card: #fff; --text: #1f2933; --muted: #637083; --line: #dde2e8; --accent: #1f6fb2; --code: #eef1f5; }
@media (prefers-color-scheme: dark) {
  :root { --bg: #15191e; --card: #1e242b; --text: #e3e8ee; --muted: #9aa5b1; --line: #313a44; --accent: #6cb4f0; --code: #28303a; }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
header { background: var(--card); border-bottom: 1px solid var(--line); padding: 14px 16px; position: sticky; top: 0; z-index: 2; }
header .in { max-width: 1100px; margin: 0 auto; display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; }
header b { font-size: 17px; }
header span { color: var(--muted); }
header nav { margin-left: auto; display: flex; gap: 6px; }
header nav a { padding: 5px 12px; border-radius: 6px; text-decoration: none; color: var(--accent); border: 1px solid var(--line); }
header nav a.on { background: var(--accent); color: #fff; border-color: var(--accent); }
.wrap { max-width: 1100px; margin: 0 auto; padding: 16px; display: grid; grid-template-columns: 230px 1fr; gap: 20px; }
aside { position: sticky; top: 76px; align-self: start; font-size: 13.5px; }
aside a { display: block; padding: 4px 8px; color: var(--muted); text-decoration: none; border-left: 2px solid var(--line); }
aside a:hover { color: var(--accent); border-color: var(--accent); }
main { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 8px 28px 28px; min-width: 0; }
h1 { font-size: 26px; margin: 18px 0 8px; }
h2 { font-size: 20px; margin: 30px 0 10px; padding-bottom: 6px; border-bottom: 1px solid var(--line); }
h3 { font-size: 16.5px; margin: 22px 0 8px; }
h4 { font-size: 15px; margin: 18px 0 6px; }
a { color: var(--accent); }
code { background: var(--code); padding: 1px 5px; border-radius: 4px; font-size: 13px; }
pre { background: var(--code); padding: 12px; border-radius: 6px; overflow-x: auto; }
pre code { padding: 0; }
table { border-collapse: collapse; width: 100%; margin: 10px 0 16px; font-size: 14px; display: block; overflow-x: auto; }
th, td { border: 1px solid var(--line); padding: 6px 10px; text-align: left; vertical-align: top; }
th { background: var(--code); }
blockquote { margin: 10px 0; padding: 8px 14px; border-left: 3px solid var(--accent); background: var(--code); border-radius: 0 6px 6px 0; }
@media (max-width: 800px) { .wrap { grid-template-columns: 1fr; } aside { display: none; } main { padding: 4px 16px 20px; } }
</style>
</head>
<body>
<header><div class="in">
  <b><?php echo htmlspecialchars($name); ?></b><span>version <?php echo htmlspecialchars($version); ?></span>
  <nav>
    <a href="<?php echo $self; ?>index" class="<?php echo $page == 'index' ? 'on' : ''; ?>">Documentation</a>
    <a href="<?php echo $self; ?>changelog" class="<?php echo $page == 'changelog' ? 'on' : ''; ?>">Changelog</a>
  </nav>
</div></header>
<div class="wrap">
  <aside><?php foreach ($toc as $t) {
      echo '<a href="#' . $t[0] . '">' . docInline($t[1]) . '</a>';
  } ?></aside>
  <main><?php echo $body; ?></main>
</div>
</body>
</html>
