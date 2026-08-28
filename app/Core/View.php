<?php

declare(strict_types=1);

namespace Khatauat\Core;

final class View
{
    public static function render(string $view, array $data = [], string $layout = 'layout'): void
    {
        $viewFile = \root_path('resources/views/' . $view . '.php');
        if (!is_file($viewFile)) {
            throw new \RuntimeException('View not found: ' . $view);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();
        $layoutFile = \root_path('resources/views/' . $layout . '.php');
        require $layoutFile;
    }
}
