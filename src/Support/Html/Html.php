<?php

declare(strict_types=1);

namespace Myxa\Support\Html;

use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Tiny PHP view renderer for HTML responses.
 */
final class Html
{
    private readonly string $basePath;

    public function __construct(string $basePath)
    {
        $resolvedBasePath = realpath($basePath);
        if ($resolvedBasePath === false || !is_dir($resolvedBasePath)) {
            throw new InvalidArgumentException(sprintf(
                'HTML view base path [%s] does not exist.',
                $basePath,
            ));
        }

        $this->basePath = rtrim($resolvedBasePath, DIRECTORY_SEPARATOR);
    }

    /**
     * Return the resolved base path used for view lookup.
     */
    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Determine whether a named PHP view exists in the base path.
     */
    public function exists(string $view): bool
    {
        $path = $this->viewPath($view);

        return is_file($path) && is_readable($path);
    }

    /**
     * Render a PHP view file with extracted local variables.
     *
     * Templates receive two helper variables:
     * - `$_html`: the current renderer for nested partials/layouts
     * - `$_e`: HTML escaping closure for safe output
     *
     * @param array<string, mixed> $data
     */
    public function render(string $view, array $data = []): string
    {
        $path = $this->viewPath($view);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('View [%s] was not found at [%s].', $view, $path));
        }

        return $this->renderPath($path, $data);
    }

    /**
     * Render a body view inside a layout.
     *
     * The rendered body is injected into the layout using the provided key.
     *
     * @param array<string, mixed> $pageData
     * @param array<string, mixed> $layoutData
     */
    public function renderPage(
        string $pageView,
        array $pageData = [],
        string $layoutView = 'layouts/app',
        array $layoutData = [],
        string $bodyKey = 'body',
    ): string {
        if ($bodyKey === '') {
            throw new InvalidArgumentException('Layout body key cannot be empty.');
        }

        $body = $this->render($pageView, $pageData);

        return $this->render($layoutView, [
            ...$layoutData,
            $bodyKey => $body,
        ]);
    }

    /**
     * Escape a value for safe HTML output.
     */
    public static function escape(null|bool|int|float|string|Stringable $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8', false);
    }

    private function viewPath(string $view): string
    {
        $relativePath = $this->normalizeView($view);

        return $this->basePath . DIRECTORY_SEPARATOR . $relativePath;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderPath(string $path, array $data): string
    {
        ob_start();

        $_html = $this;
        $_e = static fn (null|bool|int|float|string|Stringable $value): string => self::escape($value);

        extract($data, \EXTR_SKIP);

        try {
            include $path;
        } catch (Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        return (string) ob_get_clean();
    }

    private function normalizeView(string $view): string
    {
        $normalized = trim(str_replace('\\', '/', $view));
        if ($normalized === '') {
            throw new InvalidArgumentException('View name cannot be empty.');
        }

        if (str_contains($normalized, "\x00")) {
            throw new InvalidArgumentException('View name cannot contain null bytes.');
        }

        $normalized = ltrim($normalized, '/');
        if ($normalized === '') {
            throw new InvalidArgumentException('View name cannot be empty.');
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
            throw new InvalidArgumentException(sprintf('View [%s] cannot traverse outside the base path.', $view));
        }

        if (!str_ends_with($normalized, '.php')) {
            $normalized .= '.php';
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    }
}
