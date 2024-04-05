<?php

declare(strict_types=1);

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var Concrete\Core\Page\View\PageView $view
 * @var array $vars
 */

extract($vars);

/**
 * @var Concrete\Core\Form\Service\Form $form
 * @var string $environment
 * @var array $environments
 * @var array $defaultBaseURLs
 * @var array $baseURLs
 * @var array $defaultApiKeys
 * @var array $apiKeys
 */
?>

<div class="form-group">
    <?= $form->label('nexiEnvironment', t('Environment to be used')) ?>
    <?= $form->select('nexiEnvironment', $environments, $environment) ?>
</div>

<?php
foreach ($environments as $environmentKey => $environmentName) {
    ?>
    <div id="nexiEnvironment-<?= $environmentKey ?>"<?= $environmentKey === $environment ? '' : ' style="display:none"' ?>>
        <div class="form-group">
            <?= $form->label('nexiApiKey_' . $environmentKey, t('API Key (environment: %s)', h($environmentName))) ?>
            <?= $form->password('nexiApiKey_' . $environmentKey, $apiKeys[$environmentKey]) ?>
            <?php
            if ($defaultApiKeys[$environmentKey] !== '') {
                ?>
                <div class="small text-muted">
                	<?= t('Leave empty to use the default value (%s)', '<code>' . h($defaultApiKeys[$environmentKey]) . '</code>') ?>
                </div>
                <?php
            }
            ?>
        </div>
        <div class="form-group">
            <?= $form->label('nexiBaseURL_' . $environmentKey, t('Base URL (environment: %s)', h($environmentName))) ?>
            <?= $form->text('nexiBaseURL_' . $environmentKey, $baseURLs[$environmentKey]) ?>
            <?php
            if ($defaultBaseURLs[$environmentKey] !== '') {
                ?>
                <div class="small text-muted">
                	<?= t('Leave empty to use the default value (%s)', '<code>' . h($defaultBaseURLs[$environmentKey]) . '</code>') ?>
                </div>
                <?php
            }
            ?>
        </div>
    </div>
    <?php
}
?>
<script>$(document).ready(function() {

var $environment = $('#nexiEnvironment');
$environment
    .on('change', function () {
        var enviromnent = $environment.val();
        <?= json_encode(array_keys($environments)) ?>.forEach(function (env) {
            $('#nexiEnvironment-' + env).toggle(env === enviromnent);
        });
    })
    .trigger('change')
;

});</script>
