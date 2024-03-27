<?php

declare(strict_types=1);

use Concrete\Package\CommunityStoreNexi\Nexi\Configuration;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var Concrete\Core\View\View $view
 * @var array $vars
 */

extract($vars);

/**
 * @var string $environment
 * @var MLocati\Nexi\Entity\PaymentMethod[] $paymentMethods
 * @var MLocati\Nexi\Dictionary\TestCard $testCard
 */

?>
<div>
    <?php
    if ($paymentMethods === []) {
        echo t('We accept the most used credit cards.');
    } else {
        ?>
        <?= t('We accept these credit cards:') ?>
        <div>
            <?php
            foreach ($paymentMethods as $paymentMethod) {
                ?>
                <img
                    alt="<?= h($paymentMethod->getCircuit() ?? '') ?>"
                    src="<?= h($paymentMethod->getImageLink()) ?>"
                    title="<?= h(t('Pay with %s', $paymentMethod->getCircuit() ?? '')) ?>"
                    style="display: inline; max-width: 48px"
                />
                <?php
            }
            ?>
        </div>
        <?php
    }
    ?>
</div>
<?php
if ($environment === Configuration::ENVIRONMENT_SANDBOX) {
    ?>
    <div class="alert alert-info">
        <?= h(t('This payment method is currently in "test" mode.')) ?><br />
        <?= t('That means that even if you provide your credit card details, you will not actually be charged anything.') ?><br />
        <?php
        $goodCards = $testCard->getCards(true);
        if ($goodCards !== []) {
            $cardsPrinter = static function (array $cards) {
                ?>
                <ul>
                    <?php
                    foreach ($cards as $card) {
                        /** @var MLocati\Nexi\Dictionary\TestCard\Card $card */
                        ?>
                        <li>
                            <span class="badge text-bg-primary"><?= h($card->getCircuit()) ?></span>
                            <code><?= h($card->getFormattedCardNumber()) ?></code>
                            <span class="badge text-bg-primary"><?= t('Expiration') ?></span>
                            <code><?= h($card->getExpiry()) ?></code>
                            <span class="badge text-bg-primary">CVV</span>
                            <code><?= h($card->getCvv()) ?></code>
                        </li>
                        <?php
                    }
                    ?>
                </ul>
                <?php
            };
            ?>
            <div>
                <?= t('In order to test the payment method you can use these values:') ?>
                <?php $cardsPrinter($goodCards); ?>
            </div>
            <?php
            $badCards = $testCard->getCards(false);
            if ($badCards !== []) {
                ?>
                <div>
                    <?= t('In order to test FAILED payments you can use these values:') ?>
                    <?php $cardsPrinter($badCards); ?>
                </div>
                <?php
            }
        }
        ?>
    </div>
    <?php
}
