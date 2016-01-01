<?php

/**
 * Podium Module
 * Yii 2 Forum Module
 */
namespace bizley\podium\models;

use bizley\podium\components\Helper;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\helpers\Html;
use yii\helpers\HtmlPurifier;

/**
 * Meta model
 * User's meta data.
 *
 * @author Paweł Bizley Brzozowski <pb@human-device.com>
 * @since 0.1
 * @property integer $id
 * @property integer $user_id
 * @property string $location
 * @property string $signature
 * @property integer $gravatar
 * @property string $avatar
 * @property integer $created_at
 * @property integer $updated_at
 */
class Meta extends ActiveRecord
{

    const MAX_WIDTH  = 165;
    const MAX_HEIGHT = 165;
    const MAX_SIZE   = 204800;
    
    /**
     * @var mixed Avatar image
     */
    public $image;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%podium_user_meta}}';
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [TimestampBehavior::className()];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['location', 'signature'], 'trim'],        
            ['location', 'filter', 'filter' => function($value) {
                return HtmlPurifier::process(Html::encode($value));
            }],            
            ['gravatar', 'boolean'],
            ['image', 'image', 'mimeTypes' => 'image/png, image/jpeg, image/gif', 'maxWidth' => self::MAX_WIDTH, 'maxHeight' => self::MAX_HEIGHT, 'maxSize' => self::MAX_SIZE],
            ['signature', 'filter', 'filter' => function($value) {
                return HtmlPurifier::process($value, Helper::podiumPurifierConfig('minimal'));
            }],
            ['signature', 'string', 'max' => 512],
        ];
    }
}
