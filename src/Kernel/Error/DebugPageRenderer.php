<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Error;

/**
 * DebugPageRenderer — developer-facing HTML error page (debug mode only).
 *
 * A dependency-free renderer that turns a Throwable into a rich diagnostics page:
 * exception banner, source-code preview around the failing line, an expandable
 * execution trace, and environment/request info cards. Ported from the 0.3
 * HandleExceptions debug UI.
 *
 * Lives in the kernel so BOTH error layers can reuse it without breaking the
 * layering rule (kernel may not depend on the project layer, but the project
 * layer may depend on the kernel):
 *   - {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages\ErrorStage}
 *     renders it for in-request exceptions when the client wants HTML.
 *   - {@see \App\Bootstrap\Environment\ErrorGuard} renders it for pre-kernel and
 *     fatal failures that never reach the pipeline.
 *
 * SECURITY: this exposes source code, paths and a stack trace. Callers MUST gate
 * it behind a debug check — it is never rendered in production.
 */
final class DebugPageRenderer
{
    /** Render the full HTML debug page for a browser. */
    public static function renderHtml(\Throwable $e, ?string $basePath = null): string
    {
        $class   = htmlspecialchars($e::class);
        $message = htmlspecialchars($e->getMessage());
        $file    = htmlspecialchars(self::sanitizePath($e->getFile(), $basePath));
        $line    = $e->getLine();

        $preview = self::filePreview($e->getFile(), $line);
        $frames  = self::framesHtml($e, $basePath);
        $cards   = self::infoCards();

        return self::shell($class, $message, $file, $line, $preview, $frames, $cards);
    }

    /** Render an ANSI-coloured trace for the terminal (CLI debug). */
    public static function renderCli(\Throwable $e, ?string $basePath = null): string
    {
        $red = "\033[1;31m"; $yellow = "\033[1;33m"; $cyan = "\033[1;36m";
        $magenta = "\033[1;35m"; $green = "\033[1;32m"; $gray = "\033[90m";
        $white = "\033[1;37m"; $reset = "\033[0m"; $dim = "\033[2m";

        $bar = $gray . str_repeat('═', 70) . $reset;
        $out  = "\n{$bar}\n{$magenta}⚡ HKM Exception{$reset}\n{$bar}\n\n";
        $out .= "{$red}✗ " . $e::class . "{$reset}\n\n";
        $out .= "{$cyan}Message:{$reset}\n{$yellow}" . $e->getMessage() . "{$reset}\n\n";
        $out .= "{$cyan}Location:{$reset}\n{$white}" . self::sanitizePath($e->getFile(), $basePath) . "{$reset}{$dim} at line {$reset}{$green}" . $e->getLine() . "{$reset}\n\n";

        $out .= "{$cyan}Stack Trace:{$reset}\n";
        foreach ($e->getTrace() as $i => $f) {
            $loc  = self::sanitizePath($f['file'] ?? 'unknown', $basePath) . ':' . ($f['line'] ?? 0);
            $call = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . '()';
            $out .= "{$gray}#{$i}{$reset} {$dim}{$loc}{$reset}\n    {$yellow}{$call}{$reset}\n";
        }

        return $out . "\n{$bar}\n";
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private static function sanitizePath(string $path, ?string $basePath): string
    {
        if ($basePath !== null && $basePath !== '' && str_starts_with($path, $basePath)) {
            return ltrim(substr($path, strlen($basePath)), '/\\');
        }
        return $path;
    }

    private static function filePreview(string $file, int $errorLine, int $context = 10): string
    {
        if (!is_file($file) || !is_readable($file)) {
            return '<div class="code-line"><div class="line-number">?</div><div class="line-content">File not accessible</div></div>';
        }

        $lines = file($file);
        if ($lines === false) {
            return '';
        }
        $total = count($lines);
        $start = max(1, $errorLine - $context);
        $end   = min($total, $errorLine + $context);

        $html = '';
        for ($i = $start; $i <= $end; $i++) {
            $content = htmlspecialchars(rtrim($lines[$i - 1]));
            $hl      = $i === $errorLine ? ' highlight' : '';
            $html .= '<div class="code-line' . $hl . '">'
                . '<div class="line-number' . $hl . '">' . $i . '</div>'
                . '<div class="line-content">' . ($content !== '' ? $content : ' ') . '</div></div>';
        }

        return $html;
    }

    private static function framesHtml(\Throwable $e, ?string $basePath): string
    {
        $html = '';
        foreach ($e->getTrace() as $index => $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $call = htmlspecialchars(($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()');
            $loc  = htmlspecialchars(self::sanitizePath($file, $basePath)) . ':' . $line;

            $html .= '<div class="trace-item"><div class="trace-header">'
                . '<div class="trace-index">' . $index . '</div>'
                . '<div class="trace-content"><div class="trace-function">' . $call . '</div>'
                . '<div class="trace-location">' . $loc . '</div></div>'
                . '<div class="trace-expand">▶</div></div>';

            if ($file !== 'unknown' && is_file($file)) {
                $html .= '<div class="trace-detail"><div class="code-viewer">'
                    . self::filePreview($file, (int) $line, 5) . '</div></div>';
            }
            $html .= '</div>';
        }

        return $html;
    }

    private static function infoCards(): string
    {
        $env   = htmlspecialchars((string) ($_ENV['APP_ENV'] ?? 'unknown'));
        $debug = htmlspecialchars((string) ($_ENV['APP_DEBUG'] ?? 'false'));

        $html = '<div class="info-card"><h4>🌍 Environment</h4>'
            . self::row('Mode', $env) . self::row('Debug', $debug) . self::row('PHP', PHP_VERSION)
            . '</div>';

        if (PHP_SAPI !== 'cli') {
            $html .= '<div class="info-card"><h4>🌐 Request</h4>'
                . self::row('Method', htmlspecialchars((string) ($_SERVER['REQUEST_METHOD'] ?? 'N/A')))
                . self::row('URI', htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? 'N/A')))
                . self::row('IP', htmlspecialchars((string) ($_SERVER['REMOTE_ADDR'] ?? 'N/A')))
                . '</div>';
        }

        return $html;
    }

    private static function row(string $key, string $val): string
    {
        return '<div class="info-row"><div class="info-key">' . $key . '</div><div class="info-val">' . $val . '</div></div>';
    }

    private static function shell(
        string $class,
        string $message,
        string $file,
        int $line,
        string $preview,
        string $frames,
        string $cards,
    ): string {
        return '<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HKM Exception • ' . $class . '</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:"Monaco","Menlo","Ubuntu Mono","Courier New",monospace; background:#1a1a1a; color:#ccc; line-height:1.6; }
.error-container { min-height:100vh; padding:40px 20px; max-width:1400px; margin:0 auto; }
.hkm-brand { display:flex; align-items:center; gap:12px; margin-bottom:30px; padding-bottom:20px; border-bottom:2px solid #333; }
.hkm-logo { width:45px; height:45px; background:#c94040; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:18px; color:#0a0a0a; border:2px solid #333; }
.hkm-name { font-size:24px; font-weight:700; color:#c94040; }
.hkm-version { font-size:12px; color:#666; margin-left:auto; }
.error-main { display:grid; grid-template-columns:minmax(0,1fr) minmax(260px,380px); gap:25px; margin-bottom:25px; }
.error-card { background:#2a1a1a; padding:35px; border:2px solid #333; }
.exception-badge { display:inline-block; background:#b33636; color:#0a0a0a; padding:6px 14px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:1px; margin-bottom:15px; }
.error-title { font-size:28px; font-weight:700; line-height:1.3; margin-bottom:20px; color:#d97575; overflow-wrap:break-word; word-break:break-word; }
.error-meta { display:flex; align-items:center; gap:20px; font-size:13px; color:#999; }
.error-meta span { display:flex; align-items:center; gap:6px; }
.info-sidebar { display:flex; flex-direction:column; gap:15px; }
.info-card { background:#252525; padding:20px; border:1px solid #333; }
.info-card h4 { font-size:11px; text-transform:uppercase; letter-spacing:1px; color:#b33636; margin-bottom:12px; }
.info-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #222; font-size:13px; }
.info-row:last-child { border-bottom:none; }
.info-key { color:#888; }
.info-val { color:#ccc; text-align:right; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.content-section { background:#252525; border:1px solid #333; margin-bottom:20px; overflow:hidden; }
.section-header { background:#1a1a1a; padding:18px 25px; border-bottom:2px solid #333; display:flex; align-items:center; gap:10px; }
.section-header h3 { font-size:14px; font-weight:600; color:#fff; text-transform:uppercase; letter-spacing:.5px; }
.code-viewer { background:#1f1f1f; overflow-x:auto; }
.code-line { display:flex; font-size:13px; line-height:1.6; }
.code-line:hover { background:#2a2a2a; }
.code-line.highlight { background:#3a2020; border-left:4px solid #666; }
.line-number { padding:10px 20px; color:#666; text-align:right; min-width:70px; user-select:none; border-right:1px solid #333; background:#252525; }
.line-number.highlight { color:#d97575; font-weight:700; background:#2a1a1a; }
.line-content { padding:10px 20px; white-space:pre; overflow-x:auto; flex:1; color:#aaa; }
.trace-list { padding:15px; }
.trace-item { background:#1f1f1f; border:1px solid #333; margin-bottom:12px; overflow:hidden; }
.trace-item:hover { border-color:#666; }
.trace-header { padding:16px 20px; cursor:pointer; display:flex; align-items:flex-start; gap:15px; }
.trace-index { background:#b33636; color:#0a0a0a; min-width:32px; height:32px; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; flex-shrink:0; border:1px solid #333; }
.trace-content { flex:1; min-width:0; }
.trace-function { font-size:14px; color:#d97575; font-weight:600; margin-bottom:6px; }
.trace-location { font-size:12px; color:#888; }
.trace-expand { color:#666; font-size:20px; flex-shrink:0; }
.trace-item.active .trace-expand { transform:rotate(90deg); color:#c94040; }
.trace-detail { display:none; border-top:1px solid #333; background:#252525; }
.trace-detail.active { display:block; }
@media (max-width:1024px){ .error-main { grid-template-columns:1fr; } .info-sidebar { flex-direction:row; flex-wrap:wrap; } .info-card { flex:1; min-width:250px; } }
</style></head>
<body><div class="error-container">
<div class="hkm-brand"><div class="hkm-logo">HKM</div><div class="hkm-name">HKM Kernel</div><div class="hkm-version">debug</div></div>
<div class="error-main">
<div class="error-card"><div class="exception-badge">' . $class . '</div>
<div class="error-title">' . $message . '</div>
<div class="error-meta"><span>📄 ' . $file . '</span><span>📍 Line ' . $line . '</span></div></div>
<div class="info-sidebar">' . $cards . '</div></div>
<div class="content-section"><div class="section-header"><h3>💻 Source Code</h3></div>
<div class="code-viewer">' . $preview . '</div></div>
<div class="content-section"><div class="section-header"><h3>🔍 Execution Trace</h3></div>
<div class="trace-list">' . $frames . '</div></div>
</div>
<script>
document.querySelectorAll(".trace-header").forEach(h=>h.addEventListener("click",()=>{
  const i=h.parentElement,d=i.querySelector(".trace-detail");
  i.classList.toggle("active"); if(d) d.classList.toggle("active");
}));
</script></body></html>';
    }
}
