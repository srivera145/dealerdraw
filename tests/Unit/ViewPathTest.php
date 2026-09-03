<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\Core\View;
use PHPUnit\Framework\TestCase;

/**
 * Regression: queue jobs render views (the winner email) from CLI processes
 * that never call View::setPath(). Before View defaulted its path, every
 * winner email failed to render from the queue worker - silently, reported
 * only as "email failed" on the win row.
 */
class ViewPathTest extends TestCase
{
    public function testViewPathResolvesWithoutAnExplicitSetPath(): void
    {
        $path = View::path();

        self::assertNotSame('', $path);
        self::assertDirectoryExists($path);
    }

    public function testTheWinnerEmailTemplateIsReachableFromThatPath(): void
    {
        self::assertFileExists(View::path() . '/emails/winner.php');
    }
}
