<?php

/**
 * Podium Module
 * Yii 2 Forum Module
 * @author Paweł Bizley Brzozowski <pb@human-device.com>
 * @since 0.1
 */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\bootstrap\ActiveForm;

$this->title = Yii::t('podium/view', $model->isNewRecord ? 'New Category' : 'Edit Category');
$this->params['breadcrumbs'][] = ['label' => Yii::t('podium/view', 'Administration Dashboard'), 'url' => ['admin/index']];
$this->params['breadcrumbs'][] = ['label' => Yii::t('podium/view', 'Forums'), 'url' => ['admin/categories']];
$this->params['breadcrumbs'][] = $this->title;

$this->registerJs("$('[data-toggle=\"popover\"]').popover();");

?>
<?= $this->render('/elements/admin/_navbar', ['active' => 'categories']); ?>
<br>
<div class="row">
    <div class="col-sm-3">
        <ul class="nav nav-pills nav-stacked">
            <li role="presentation"><a href="<?= Url::to(['admin/categories']) ?>"><span class="glyphicon glyphicon-list"></span> <?= Yii::t('podium/view', 'Categories List') ?></a></li>
<?php foreach ($categories as $category): ?>
            <li role="presentation" class="<?= $model->id == $category->id ? 'active' : '' ?>"><a href="<?= Url::to(['admin/edit-category', 'id' => $category->id]) ?>"><span class="glyphicon glyphicon-chevron-right"></span> <?= Html::encode($category->name) ?></a></li>
<?php endforeach; ?>
            <li role="presentation" class="<?= $model->isNewRecord ? 'active' : '' ?>"><a href="<?= Url::to(['admin/new-category']) ?>"><span class="glyphicon glyphicon-plus"></span> <?= Yii::t('podium/view', 'Create new category') ?></a></li>
        </ul>
    </div>
    <div class="col-sm-6">
        <div class="panel panel-default">
            <?php $form = ActiveForm::begin(['id' => 'edit-category-form']); ?>
                <div class="panel-heading">
                    <h3 class="panel-title"><?= Yii::t('podium/view', $model->isNewRecord ? 'New Category' : 'Edit Category') ?></h3>
                </div>
                <div class="panel-body">
                    <div class="row">
                        <div class="col-sm-12">
                            <?= $form->field($model, 'name')->textInput([
                                'data-container' => 'body',
                                'data-toggle'    => 'popover',
                                'data-placement' => 'right',
                                'data-content'   => Yii::t('podium/view', 'Name must contain only letters, digits, underscores and spaces (255 characters max).'),
                                'data-trigger'   => 'focus'
                            ])->label(Yii::t('podium/view', "Category's Name")) ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-12">
                            <?= $form->field($model, 'visible')->checkbox(['uncheck' => 0, 'aria-describedby' => 'help-visible'])->label(Yii::t('podium/view', 'Category visible for anonymous guests')) ?>
                            <small id="help-visible" class="help-block"><?= Yii::t('podium/view', 'You can turn off visibility for each individual forum in the category as well.') ?></small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-12">
                            <?= $form->field($model, 'keywords')->textInput([
                                'placeholder'    => Yii::t('podium/view', 'Optional keywords'),
                                'data-container' => 'body',
                                'data-toggle'    => 'popover',
                                'data-placement' => 'right',
                                'data-content'   => Yii::t('podium/view', 'Meta keywords tag (leave empty to get global value).'),
                                'data-trigger'   => 'focus'
                            ])->label(Yii::t('podium/view', "Category's Meta Keywords")) ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-12">
                            <?= $form->field($model, 'description')->textInput([
                                'placeholder'    => Yii::t('podium/view', 'Optional description'),
                                'data-container' => 'body',
                                'data-toggle'    => 'popover',
                                'data-placement' => 'right',
                                'data-content'   => Yii::t('podium/view', 'Meta description tag (leave empty to get global value).'),
                                'data-trigger'   => 'focus'
                            ])->label(Yii::t('podium/view', "Category's Meta Description")) ?>
                        </div>
                    </div>
                </div>
                <div class="panel-footer">
                    <div class="row">
                        <div class="col-sm-12">
                            <?= Html::submitButton('<span class="glyphicon glyphicon-ok-sign"></span> ' . Yii::t('podium/view', $model->isNewRecord ? 'Create New Category' : 'Save Category'), ['class' => 'btn btn-block btn-primary', 'name' => 'save-button']) ?>
                        </div>
                    </div>
                </div>
            <?php ActiveForm::end(); ?>
        </div>
    </div>
</div><br>
