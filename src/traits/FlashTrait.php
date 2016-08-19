<?php

/**
 * Podium Module
 * Yii 2 Forum Module
 */
namespace bizley\podium\traits;

use Yii;

/**
 * Podium Flash Trait
 * Simplifies flash messages adding. Every message is automatically translated.
 * Prepares messages for \bizley\podium\widgets\Alert widget.
 * Former FlashBehavior.
 * 
 * @author Paweł Bizley Brzozowski <pawel@positive.codes>
 * @since 0.2
 */
trait FlashTrait
{
    /**
     * Alias for warning().
     * @param string $message the flash message to be translated.
     * @param bool $removeAfterAccess message removal after access only.
     */
    public function alert($message, $removeAfterAccess = true)
    {
        Yii::$app->session->addFlash('warning', $message, $removeAfterAccess);
    }
    
    /**
     * Adds flash message of 'danger' type.
     * @param string $message the flash message to be translated.
     * @param bool $removeAfterAccess message removal after access only.
     */
    public function danger($message, $removeAfterAccess = true)
    {
        Yii::$app->session->addFlash('danger', $message, $removeAfterAccess);
    }
    
    /**
     * Alias for danger().
     * @param string $message the flash message to be translated.
     * @param bool $removeAfterAccess message removal after access only.
     */
    public function error($message, $removeAfterAccess = true)
    {
        Yii::$app->session->addFlash('danger', $message, $removeAfterAccess);
    }
    
    /**
     * Adds flash message of given type.
     * @param string $type the type of flash message.
     * @param string $message the flash message to be translated.
     * @param bool $removeAfterAccess message removal after access only.
     */
    public function goFlash($type, $message, $removeAfterAccess = true)
    {
        Yii::$app->session->addFlash($type, $message, $removeAfterAccess);
    }
    
    /**
     * Adds flash message of 'info' type.
     * @param string $message the flash message to be translated.
     * @param bool $removeAfterAccess message removal after access only.
     */
    public function info($message, $removeAfterAccess = true)
    {
        Yii::$app->session->addFlash('info', $message, $removeAfterAccess);
    }
    
    /**
     * Alias for success().
     * @param string $message the flash message to be translated.
     * @param bool $removeAfterAccess message removal after access only.
     */
    public function ok($message, $removeAfterAccess = true)
    {
        Yii::$app->session->addFlash('success', $message, $removeAfterAccess);
    }
    
    /**
     * Adds flash message of 'success' type.
     * @param string $message the flash message to be translated.
     * @param bool $removeAfterAccess message removal after access only.
     */
    public function success($message, $removeAfterAccess = true)
    {
        Yii::$app->session->addFlash('success', $message, $removeAfterAccess);
    }
    
    /**
     * Adds flash message of 'warning' type.
     * @param string $message the flash message to be translated.
     * @param bool $removeAfterAccess message removal after access only.
     */
    public function warning($message, $removeAfterAccess = true)
    {
        Yii::$app->session->addFlash('warning', $message, $removeAfterAccess);
    } 
}
