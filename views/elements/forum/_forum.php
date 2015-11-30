<?php

/**
 * Podium Module
 * Yii 2 Forum Module
 * @author Paweł Bizley Brzozowski <pb@human-device.com>
 * @since 0.1
 */

use yii\helpers\Html;
use yii\helpers\Url;

?>
<td>
    <a href="<?= Url::to(['default/forum', 'cid' => $model->category_id, 'id' => $model->id, 'slug' => $model->slug]) ?>" class="center-block"><?= Html::encode($model->name) ?></a>
<?php if (!empty($model->sub)): ?>
    <small class="text-muted"><?= Html::encode($model->sub) ?></small>
<?php endif; ?>
</td>
<td class="text-right"><?= $model->threads ?></td>
<td class="text-right"><?= $model->posts ?></td>
<td>
<?php if (!empty($model->latest) && !empty($model->latest->thread)): ?>
    <a href="<?= Url::to(['default/show', 'id' => $model->latest->id]) ?>" class="center-block"><?= Html::encode($model->latest->thread->name) ?></a>
    <small><?= $model->latest->author->podiumTag ?> <?= Yii::$app->formatter->asDatetime($model->latest->created_at) ?></small>
<?php endif; ?>
</td>
