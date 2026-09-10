<?php
declare(strict_types=1);

namespace HeleXa\Core;

/**
 * Plain PHP templates. Data is escaped at the point of output with e().
 */
final class View
{
    private static string $basePath = '';

    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/\\');
    }

    public static function render(string $template, array $data = []): string
    {
        $file = self::resolve($template);

        $data['csrf_token'] = Csrf::token();

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    /** Renders a template inside a layout; $content becomes available to the layout. */
    public static function page(string $layout, string $template, array $data = []): string
    {
        $data['content'] = self::render($template, $data);
        return self::render($layout, $data);
    }

    public static function partial(string $template, array $data = []): void
    {
        echo self::render($template, $data);
    }

    private static function resolve(string $template): string
    {
        if (preg_match('/^[a-zA-Z0-9_\/\.\-]+$/', $template) !== 1 || str_contains($template, '..')) {
            throw new \InvalidArgumentException('Invalid template name.');
        }
        $file = self::$basePath . '/' . str_replace('.', '/', $template) . '.php';
        $real = realpath($file);
        if ($real === false || !str_starts_with($real, (string) realpath(self::$basePath))) {
            throw new \RuntimeException('Template not found: ' . $template);
        }
        return $real;
    }
}
