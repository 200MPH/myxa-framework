<?php

declare(strict_types=1);

namespace Test\Unit\Support\Html;

use InvalidArgumentException;
use Myxa\Support\Html\Html;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Html::class)]
final class HtmlTest extends TestCase
{
    private string $viewsPath;

    protected function setUp(): void
    {
        $this->viewsPath = sys_get_temp_dir() . '/myxa-html-' . uniqid('', true);

        mkdir($this->viewsPath . '/layouts', 0777, true);
        mkdir($this->viewsPath . '/pages', 0777, true);
        mkdir($this->viewsPath . '/partials', 0777, true);

        file_put_contents($this->viewsPath . '/partials/header.php', <<<'PHP'
<header><?= $_e($title) ?></header>
PHP);

        file_put_contents($this->viewsPath . '/partials/footer.php', <<<'PHP'
<footer><?= $_e($footer) ?></footer>
PHP);

        file_put_contents($this->viewsPath . '/layouts/app.php', <<<'PHP'
<?= $_html->render('partials/header', ['title' => $title]) ?>
<?= $body ?>
<?= $_html->render('partials/footer', ['footer' => $footer]) ?>
PHP);

        file_put_contents($this->viewsPath . '/pages/home.php', <<<'PHP'
<?= $_html->render('partials/header', ['title' => $title]) ?>
<main><?= $_e($message) ?></main>
PHP);
    }

    protected function tearDown(): void
    {
        $files = [
            $this->viewsPath . '/layouts/app.php',
            $this->viewsPath . '/pages/home.php',
            $this->viewsPath . '/partials/footer.php',
            $this->viewsPath . '/partials/header.php',
        ];

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $directories = [
            $this->viewsPath . '/layouts',
            $this->viewsPath . '/pages',
            $this->viewsPath . '/partials',
            $this->viewsPath,
        ];

        foreach ($directories as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testRenderRendersPhpViewsWithDataAndNestedPartials(): void
    {
        $html = new Html($this->viewsPath);

        $output = $html->render('pages/home', [
            'title' => 'Welcome <Admin>',
            'message' => 'Safe & sound',
        ]);

        self::assertSame(
            "<header>Welcome &lt;Admin&gt;</header><main>Safe &amp; sound</main>",
            preg_replace('/>\s+</', '><', trim($output)),
        );
    }

    public function testExistsChecksViewPresence(): void
    {
        $html = new Html($this->viewsPath);

        self::assertTrue($html->exists('pages/home'));
        self::assertFalse($html->exists('pages/missing'));
    }

    public function testEscapeIsAvailableForTemplateSafeOutput(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;',
            Html::escape('<script>alert("x")</script>'),
        );
    }

    public function testRenderPageInjectsBodyIntoLayout(): void
    {
        $html = new Html($this->viewsPath);

        $output = $html->renderPage(
            'pages/home',
            [
                'title' => 'Welcome <Admin>',
                'message' => 'Safe & sound',
            ],
            'layouts/app',
            [
                'title' => 'Dashboard <Root>',
                'footer' => 'All rights reserved',
            ],
        );

        self::assertSame(
            '<header>Dashboard &lt;Root&gt;</header><header>Welcome &lt;Admin&gt;</header><main>Safe &amp; sound</main><footer>All rights reserved</footer>',
            preg_replace('/>\s+</', '><', trim($output)),
        );
    }

    public function testRenderPageSupportsCustomBodyKey(): void
    {
        file_put_contents($this->viewsPath . '/layouts/custom.php', <<<'PHP'
<section><?= $slot ?></section>
PHP);

        $html = new Html($this->viewsPath);

        $output = $html->renderPage(
            'pages/home',
            [
                'title' => 'Welcome <Admin>',
                'message' => 'Safe & sound',
            ],
            'layouts/custom',
            [],
            'slot',
        );

        self::assertSame(
            '<section><header>Welcome &lt;Admin&gt;</header><main>Safe &amp; sound</main></section>',
            preg_replace('/>\s+</', '><', trim($output)),
        );

        unlink($this->viewsPath . '/layouts/custom.php');
    }

    public function testRenderPageRejectsEmptyBodyKey(): void
    {
        $html = new Html($this->viewsPath);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Layout body key cannot be empty.');

        $html->renderPage('pages/home', bodyKey: '');
    }

    public function testRenderRejectsMissingViews(): void
    {
        $html = new Html($this->viewsPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('View [missing] was not found');

        $html->render('missing');
    }

    public function testConstructorRejectsMissingBasePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTML view base path');

        new Html($this->viewsPath . '/unknown');
    }

    public function testRenderRejectsTraversalAttempt(): void
    {
        $html = new Html($this->viewsPath);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot traverse outside the base path');

        $html->render('../secrets');
    }
}
