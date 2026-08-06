<?php

declare(strict_types=1);

namespace Plugins\Logger\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;

/**
 * Shared plumbing for LoggerPort adapters: the eight level shortcuts, message
 * interpolation, and threshold filtering. Subclasses implement one method.
 */
abstract class AbstractLogger implements LoggerPort
{
    public function emergency(string|\Stringable $message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert(string|\Stringable $message, array $context = []): void     { $this->log('alert',     $message, $context); }
    public function critical(string|\Stringable $message, array $context = []): void  { $this->log('critical',  $message, $context); }
    public function error(string|\Stringable $message, array $context = []): void     { $this->log('error',     $message, $context); }
    public function warning(string|\Stringable $message, array $context = []): void   { $this->log('warning',   $message, $context); }
    public function notice(string|\Stringable $message, array $context = []): void    { $this->log('notice',    $message, $context); }
    public function info(string|\Stringable $message, array $context = []): void      { $this->log('info',      $message, $context); }
    public function debug(string|\Stringable $message, array $context = []): void     { $this->log('debug',     $message, $context); }

    /**
     * Replace {placeholder} tokens with matching context values (PSR-3 §1.2).
     *
     * Only scalars and Stringables are substituted; an array or object context
     * value stays as the literal token rather than printing "Array". The
     * unsubstituted keys still travel in the structured context, so nothing is
     * lost — it just does not get flattened into the human-readable message.
     *
     * @param array<string, mixed> $context
     */
    protected function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if ($value === null || is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return $replacements === [] ? $message : strtr($message, $replacements);
    }

    /**
     * Encode context for output. Never throws — a logger that fails takes down
     * the operation it was only supposed to observe.
     *
     * @param array<string, mixed> $context
     */
    protected function encodeContext(array $context): string
    {
        if ($context === []) {
            return '';
        }

        try {
            return (string) json_encode(
                $this->normalise($context),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\Throwable) {
            return '{"_context":"unencodable"}';
        }
    }

    /**
     * Make a context array safe to encode: exceptions become readable records,
     * other objects collapse to their class name. Prevents both a JSON failure
     * and accidentally serialising an entire object graph into a log line.
     *
     * @param  array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function normalise(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $context[$key] = [
                    'class'   => $value::class,
                    'message' => $value->getMessage(),
                    'file'    => $value->getFile() . ':' . $value->getLine(),
                ];
                continue;
            }
            if (is_array($value)) {
                $context[$key] = $this->normalise($value);
                continue;
            }
            if (is_object($value) && !$value instanceof \Stringable && !$value instanceof \JsonSerializable) {
                $context[$key] = $value::class;
            }
        }

        return $context;
    }
}
