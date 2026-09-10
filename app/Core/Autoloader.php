<?php
declare(strict_types=1);

namespace HeleXa\Core;

/**
 * PSR-4 style autoloader for the HeleXa\ namespace.
 * Resolves with realpath() and refuses anything outside the base directory,
 * so a crafted class name can never traverse the filesystem.
 */
final class Autoloader
{
    private string $baseDir;
    private string $realBase;
    private const PREFIX = 'HeleXa\\';

    public function __construct(string $baseDir)
    {
        $this->baseDir  = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
        $this->realBase = (string) realpath($this->baseDir);
    }

    public function register(): void
    {
        spl_autoload_register([$this, 'load'], true, false);
    }

    public function load(string $class): void
    {
        if (!str_starts_with($class, self::PREFIX)) {
            return;
        }
        $relative = substr($class, strlen(self::PREFIX));
        if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
            return;
        }
        $file = $this->baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
        $real = realpath($file);
        if ($real === false || !str_starts_with($real, $this->realBase) || !is_file($real)) {
            return;
        }
        require_once $real;
    }
}
