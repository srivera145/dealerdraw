<?php
$currentPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$navLinks = [
    '/admin/campaigns' => 'Campaigns',
    '/admin/wins' => 'Winners',
    '/dashboard' => 'Dashboard',
];
?>
<header class="mb-8 flex flex-wrap items-center justify-between gap-4">
    <nav class="flex items-center gap-4 text-sm">
        <?php foreach ($navLinks as $href => $label): ?>
        <a href="<?= htmlspecialchars($href) ?>"
           class="<?= str_starts_with($currentPath, $href) ? 'font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-900' ?>">
            <?= htmlspecialchars($label) ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <form method="POST" action="/logout">
        <?= \Keel\Core\Csrf::field() ?>
        <button type="submit" class="text-sm text-gray-500 hover:text-gray-900">Sign out</button>
    </form>
</header>
