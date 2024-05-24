<?php

declare(strict_types=1);

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var Concrete\Core\Page\View\PageView $view
 * @var array $vars
 */

extract($vars);

/**
 * @var stdClass $request
 */

foreach ($request as $field => $value) {
    ?>
    <input type="hidden" name="<?= h($field) ?>" value="<?= htmlspecialchars((string) $value) ?>" />
    <?php
}
