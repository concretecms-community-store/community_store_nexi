<?php

declare(strict_types=1);

use Concrete\Package\CommunityStoreNexi\Nexi\Configuration;

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
 *
 * @var string $implementation
 * @var string $implementations
 *
 * @var array $xPayDefaultBaseURLs
 * @var array $xPayBaseURLs
 * @var array $xPayAliases
 * @var array $xPayMacKeys
 *
 * @var array $xPayWebDefaultBaseURLs
 * @var array $xPayWebBaseURLs
 * @var array $xPayWebDefaultApiKeys
 * @var array $xPayWebApiKeys
 */
?>

<div class="form-group">
    <?= $form->label('nexiEnvironment', t('Environment to be used')) ?>
    <?= $form->select('nexiEnvironment', $environments, $environment) ?>
</div>

<div class="form-group">
    <?= $form->label('nexiImplementation', t('Implementation to be used')) ?>
    <?= $form->select('nexiImplementation', ($implementation === '' ? ['' => t('** Please select')] : []) + $implementations, $implementation) ?>
</div>

<?php
foreach ($environments as $environmentKey => $environmentName) {
    ?>
    <div id="nexiEnvironment-<?= Configuration::IMPLEMENTATION_XPAY ?>-<?= $environmentKey ?>"<?= $environmentKey === $environment && $implementation === Configuration::IMPLEMENTATION_XPAY ? '' : ' style="display:none"' ?>>
        <div class="form-group">
            <?= $form->label('nexiXPayBaseURL_' . $environmentKey, t('Base URL (environment: %s)', h($environmentName))) ?>
            <?= $form->text('nexiXPayBaseURL_' . $environmentKey, $xPayBaseURLs[$environmentKey]) ?>
            <?php
            if ($xPayDefaultBaseURLs[$environmentKey] !== '') {
                ?>
                <div class="small text-muted">
                    <?= t('Leave empty to use the default value (%s)', '<code>' . h($xPayDefaultBaseURLs[$environmentKey]) . '</code>') ?>
                </div>
                <?php
            }
            ?>
        </div>
        <div class="form-group">
            <?= $form->label('nexiXPayAlias_' . $environmentKey, t('Merchant Alias (environment: %s)', h($environmentName))) ?>
            <?= $form->text('nexiXPayAlias_' . $environmentKey, $xPayAliases[$environmentKey]) ?>
        </div>
        <div class="form-group">
            <?= $form->label('nexiXPayMacKey_' . $environmentKey, t('MAC Key (environment: %s)', h($environmentName))) ?>
            <?= $form->password('nexiXPayMacKey_' . $environmentKey, $xPayMacKeys[$environmentKey]) ?>
        </div>
    </div>
    <div id="nexiEnvironment-<?= Configuration::IMPLEMENTATION_XPAYWEB ?>-<?= $environmentKey ?>"<?= $environmentKey === $environment && $implementation === Configuration::IMPLEMENTATION_XPAYWEB ? '' : ' style="display:none"' ?>>
        <div class="form-group">
            <?= $form->label('nexiXPayWebBaseURL_' . $environmentKey, t('Base URL (environment: %s)', h($environmentName))) ?>
            <?= $form->text('nexiXPayWebBaseURL_' . $environmentKey, $xPayWebBaseURLs[$environmentKey]) ?>
            <?php
            if ($xPayWebDefaultBaseURLs[$environmentKey] !== '') {
                ?>
                <div class="small text-muted">
                	<?= t('Leave empty to use the default value (%s)', '<code>' . h($xPayWebDefaultBaseURLs[$environmentKey]) . '</code>') ?>
                </div>
                <?php
            }
            ?>
        </div>
        <div class="form-group">
            <?= $form->label('nexiXPayWebApiKey_' . $environmentKey, t('API Key (environment: %s)', h($environmentName))) ?>
            <?= $form->password('nexiXPayWebApiKey_' . $environmentKey, $xPayWebApiKeys[$environmentKey]) ?>
            <?php
            if ($xPayWebDefaultApiKeys[$environmentKey] !== '') {
                ?>
                <div class="small text-muted">
                    <?= t('Leave empty to use the default value (%s)', '<code>' . h($xPayWebDefaultApiKeys[$environmentKey]) . '</code>') ?>
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

var $implementation = $('#nexiImplementation');
var $environment = $('#nexiEnvironment');
$implementation.add($environment)
    .on('change', function () {
        const enviromnent = $environment.val();
        const implementation = $implementation.val();
        <?= json_encode(array_keys($implementations)) ?>.forEach(function (impl) {
            <?= json_encode(array_keys($environments)) ?>.forEach(function (env) {
                $(`#nexiEnvironment-${impl}-${env}`).toggle(impl === implementation && env === enviromnent);
            });
        });
    })
    .trigger('change')
;

});</script>
