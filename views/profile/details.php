<?php

/**
 * Podium Module
 * Yii 2 Forum Module
 * @author Paweł Bizley Brzozowski <pb@human-device.com>
 * @since 0.1
 */

use bizley\podium\components\Helper;
use bizley\podium\Module as PodiumModule;
use cebe\gravatar\Gravatar;
use kartik\select2\Select2;
use yii\bootstrap\ActiveForm;
use yii\helpers\Html;

$this->title = Yii::t('podium/view', 'Account Details');
$this->params['breadcrumbs'][] = ['label' => Yii::t('podium/view', 'My Profile'), 'url' => ['profile/index']];
$this->params['breadcrumbs'][] = $this->title;

$this->registerJs("$('[data-toggle=\"popover\"]').popover();");
$this->registerJs("$('#show-email').click(function (e) { e.preventDefault(); $('#details-email').removeClass('hide'); $('#user-new_email').prop('disabled', false); $(this).addClass('hide'); });");
if (PodiumModule::getInstance()->userComponent == PodiumModule::USER_OWN) {
    $this->registerJs("$('#show-password').click(function (e) { e.preventDefault(); $('#details-password').removeClass('hide'); $('#user-new_password').prop('disabled', false); $('#user-new_password_repeat').prop('disabled', false); $(this).addClass('hide'); });");
}

?>
<div class="row">
    <div class="col-sm-3">
        <?= $this->render('/elements/profile/_navbar', ['active' => 'details']) ?>
    </div>
    <div class="col-sm-6">
        <div class="panel panel-default">
            <?php $form = ActiveForm::begin(['id' => 'details-form']); ?>
                <div class="panel-body">
                    <div class="row">
                        <div class="col-sm-12">
                            <?= $form->field($model, 'username')->textInput([
                                'data-container' => 'body',
                                'data-toggle'    => 'popover',
                                'data-placement' => 'right',
                                'data-content'   => Yii::t('podium/view', 'Username must start with a letter, contain only letters, digits and underscores, and be at least 3 characters long.'),
                                'data-trigger'   => 'focus'
                            ])->label(Yii::t('podium/view', 'Username')) ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-12">
                            <?= $form->field($model, 'timezone')->widget(Select2::classname(), [
                                'data'          => Helper::timeZones(),
                                'theme'         => Select2::THEME_KRAJEE,
                                'options'       => ['placeholder' => Yii::t('podium/view', 'Select your time zone for proper dates display...')],
                                'pluginOptions' => [
                                    'allowClear' => true
                                ],
                            ])->label(Yii::t('podium/view', 'Time Zone')); ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-12">
                            <?= $form->field($model, 'anonymous')->checkbox(['uncheck' => 0])->label(Yii::t('podium/view', 'Hide username while forum viewing')) ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <button class="btn btn-warning btn-block <?= !empty($model->new_email) ? 'hide' : '' ?>" id="show-email"><span class="glyphicon glyphicon-envelope"></span> <?= Yii::t('podium/view', 'Change e-mail address') ?></button>
                            </div>
                        </div>
<?php if (PodiumModule::getInstance()->userComponent == PodiumModule::USER_OWN): ?>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <button class="btn btn-warning btn-block <?= !empty($model->new_password) ? 'hide' : '' ?>" id="show-password"><span class="glyphicon glyphicon-lock"></span> <?= Yii::t('podium/view', 'Change password') ?></button>
                            </div>
                        </div>
<?php endif; ?>
                    </div>
                    <div class="row <?= empty($model->new_email) ? 'hide' : '' ?>" id="details-email">
                        <div class="col-sm-12">
                            <?= $form->field($model, 'new_email')->textInput([
                                'placeholder'    => Yii::t('podium/view', "Leave empty if you don't want to change it"),
                                'data-container' => 'body',
                                'data-toggle'    => 'popover',
                                'data-placement' => 'right',
                                'data-content'   => Yii::t('podium/view', 'New e-mail has to be activated first. Activation link will be sent to the new address.'),
                                'data-trigger'   => 'focus',
                                'autocomplete'   => 'off',
                                'disabled'       => empty($model->new_email) ? true : false
                            ])->label(Yii::t('podium/view', 'New Podium e-mail')) ?>
                        </div>
                    </div>
<?php if (PodiumModule::getInstance()->userComponent == PodiumModule::USER_OWN): ?>
                    <div id="details-password" class="<?= empty($model->new_password) ? 'hide' : '' ?>">
                        <div class="row">
                            <div class="col-sm-12">
                                <?= $form->field($model, 'new_password')->passwordInput([
                                    'placeholder'    => Yii::t('podium/view', "Leave empty if you don't want to change it"),
                                    'data-container' => 'body',
                                    'data-toggle'    => 'popover',
                                    'data-placement' => 'right',
                                    'data-content'   => Yii::t('podium/view', 'Password must contain uppercase and lowercase letter, digit, and be at least 6 characters long.'),
                                    'data-trigger'   => 'focus',
                                    'autocomplete'   => 'off',
                                    'disabled'       => empty($model->new_password) ? true : false
                                ])->label(Yii::t('podium/view', 'New password')) ?>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-12">
                                <?= $form->field($model, 'new_password_repeat')->passwordInput([
                                        'autocomplete' => 'off', 
                                        'placeholder'  => Yii::t('podium/view', "Leave empty if you don't want to change it"),
                                        'disabled'     => empty($model->new_password) ? true : false
                                    ])->label(Yii::t('podium/view', 'Repeat new password')) ?>
                            </div>
                        </div>
                    </div>
<?php endif; ?>
                </div>
                <div class="panel-footer">
                    <div class="row">
                        <div class="col-sm-12">
                            <?= $form->field($model, 'current_password')->passwordInput(['autocomplete' => 'off'])->label(Yii::t('podium/view', 'Current password')) ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-12">
                            <?= Html::submitButton('<span class="glyphicon glyphicon-ok-sign"></span> ' . Yii::t('podium/view', 'Save changes'), ['class' => 'btn btn-block btn-primary', 'name' => 'save-button']) ?>
                        </div>
                    </div>
                </div>
            <?php ActiveForm::end(); ?>
        </div>
    </div>
    <div class="col-sm-3">
<?php if (!empty($model->meta->gravatar)): ?>
        <?= Gravatar::widget([
            'email'        => $model->email,
            'defaultImage' => 'identicon',
            'rating'       => 'r',
            'options'      => [
                'alt'   => Yii::t('podium/view', 'Your Gravatar image'),
                'class' => 'img-circle img-responsive',
            ]]); ?>
<?php elseif (!empty($model->meta->avatar)): ?>
        <img class="img-circle img-responsive" src="/avatars/<?= $model->meta->avatar ?>" alt="<?= Yii::t('podium/view', 'Your avatar') ?>">
<?php else: ?>
        <img class="img-circle img-responsive" src="<?= Helper::defaultAvatar() ?>" alt="<?= Yii::t('podium/view', 'Default avatar') ?>">
<?php endif; ?>
    </div>
</div><br>
